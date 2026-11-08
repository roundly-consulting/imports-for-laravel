<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Maintenance;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Actions\Commit\FailImportAction;
use RoundlyConsulting\Imports\Actions\Rollback\FinishRollbackAction;
use RoundlyConsulting\Imports\DataTransferObjects\SweepReport;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportExpired;
use RoundlyConsulting\Imports\Events\ImportPreviewFailed;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Jobs\ValidatePreviewJob;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\SessionFiles;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;
use Throwable;

/**
 * The every-few-minutes housekeeping (`imports:sweep`): uncommitted sessions past `expire_after`
 * expire and lose their file; a commit or rollback that stopped making progress is failed and
 * its lock released — after `stale_after` when its job batch is finished, cancelled, failed or
 * gone, and after `abandon_after` whatever the batch says (jobs lost from the queue); orphaned temp
 * directories go.
 */
final readonly class SweepImportsAction
{
    public function __construct(private Container $container) {}

    public function execute(): SweepReport
    {
        $now = Clock::now();
        $expired = 0;

        ImportModels::session()::query()
            ->whereIn('status', array_map(static fn (SessionStatus $s): string => $s->value, SessionStatus::beforeCommit()))
            ->where('expires_at', '<', $now)
            ->chunkById(200, function ($sessions) use (&$expired): void {
                foreach ($sessions as $session) {
                    if ($this->expire($session)) {
                        $expired++;
                    }
                }
            });

        $stale = $now->sub(ImportsConfig::staleAfter());
        $abandoned = $now->sub(ImportsConfig::abandonAfter());
        $failedCommits = 0;
        $failedRollbacks = 0;

        $stuck = ImportModels::session()::query()
            ->whereIn('status', [SessionStatus::Committing->value, SessionStatus::RollingBack->value])
            ->where(static fn ($query) => $query->whereNull('last_progress_at')->orWhere('last_progress_at', '<', $stale))
            ->get();

        foreach ($stuck as $session) {
            $batch = $session->batch;

            if ($batch === null) {
                ImportModels::session()::query()->whereKey($session->getKey())->update(['status' => SessionStatus::Failed->value, 'lock_key' => null]);
                $failedCommits++;

                continue;
            }

            $rollingBack = $session->status === SessionStatus::RollingBack;
            $laravelId = $rollingBack ? $batch->rollback_batch_id : $batch->laravel_batch_id;
            $laravel = $laravelId === null ? null : Bus::findBatch($laravelId);
            $dead = $laravel === null || $laravel->finished() || $laravel->cancelled() || $laravel->hasFailures();
            $abandon = $session->last_progress_at === null || $session->last_progress_at->lessThan($abandoned);

            if (! $dead && ! $abandon) {
                continue;
            }

            if ($rollingBack) {
                $this->container->make(FinishRollbackAction::class)->execute(Keys::of($batch), 'abandoned');
                $failedRollbacks++;
            } else {
                $this->container->make(FailImportAction::class)->execute(Keys::of($batch), 'abandoned');
                $failedCommits++;
            }
        }

        $failedPreviews = 0;

        $validating = ImportModels::session()::query()
            ->where('status', SessionStatus::Validating->value)
            ->where(static fn ($query) => $query->whereNull('last_progress_at')->orWhere('last_progress_at', '<', $stale))
            ->get();

        foreach ($validating as $session) {
            $abandon = $session->last_progress_at === null || $session->last_progress_at->lessThan($abandoned);

            if (($abandon || ! self::validationPending($session)) && $this->failPreview($session)) {
                $failedPreviews++;
            }
        }

        $temp = TempFiles::sweep(ImportsConfig::tmpTtl());

        return new SweepReport($expired, $failedCommits, $failedRollbacks, $temp, $failedPreviews);
    }

    /**
     * Whether the session's validation job is still queued or running: its unique lock is held
     * until it finishes, so a lock this probe can take means no job is coming.
     */
    private static function validationPending(ImportSession $session): bool
    {
        $probe = new ValidatePreviewJob(Keys::of($session), $session->preview_version);
        $lock = new UniqueLock(Cache::store());

        if (! $lock->acquire($probe)) {
            return true;
        }

        $lock->release($probe);

        return false;
    }

    /**
     * `preview_failed` (reason `abandoned`) with the preview version bumped, so a late job is a
     * compare-and-set no-op.
     */
    private function failPreview(ImportSession $session): bool
    {
        $now = Clock::format(Clock::now());
        $failed = ImportModels::session()::query()
            ->whereKey($session->getKey())
            ->where('status', SessionStatus::Validating->value)
            ->where('preview_version', $session->preview_version)
            ->update([
                'status' => SessionStatus::PreviewFailed->value,
                'preview_version' => $session->preview_version + 1,
                'failure' => json_encode(['reason' => 'abandoned', 'message' => null]),
                'last_progress_at' => $now,
                'updated_at' => $now,
            ]);

        if ($failed === 0) {
            return false;
        }

        $session->refresh();
        Event::dispatch(new ImportPreviewFailed(Audits::for($session, null, Audits::actor($session)), 'abandoned'));

        return true;
    }

    /**
     * Expire an uncommitted session — unless a commit took it after the query above read it — and
     * only then delete its files.
     */
    private function expire(ImportSession $session): bool
    {
        [$disk, $path, $workingCopy] = [$session->file_disk, $session->file_path, $session->working_copy_path];

        try {
            SessionState::transition($session, static function () use ($session): void {
                ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete();
                $session->forceFill([
                    'status' => SessionStatus::Expired,
                    'preview_version' => $session->preview_version + 1,
                    'working_copy_path' => null,
                    'file_pruned_at' => Clock::now(),
                ])->save();
            });
        } catch (ImportStateException) {
            return false;
        }

        try {
            SessionFiles::deletePaths($disk, $path, $workingCopy);
        } catch (Throwable $exception) {
            report($exception);
        }

        Event::dispatch(new ImportExpired(Audits::for($session, null, null)));

        return true;
    }
}
