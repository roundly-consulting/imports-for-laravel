<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Maintenance;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\DataTransferObjects\PruneReport;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportsPruned;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\SessionFiles;
use Throwable;

/**
 * The daily retention pass (`imports:prune`): source files, working copies and row results older
 * than `storage.retention` are deleted (the session keeps `file_pruned_at`); with
 * `history.retention` set, sessions, batches and ledger rows older than it — and past their undo
 * window — go too, children before parents, in chunks. Safe to re-run.
 */
final readonly class PruneImportsAction
{
    public function execute(): PruneReport
    {
        $now = Clock::now();
        $running = [SessionStatus::Validating->value, SessionStatus::Committing->value, SessionStatus::RollingBack->value];
        $files = 0;
        $rowResults = 0;

        ImportModels::session()::query()
            ->withTrashed()
            ->whereNull('file_pruned_at')
            ->whereNotIn('status', $running)
            ->where('created_at', '<', $now->sub(ImportsConfig::retention()))
            ->chunkById(200, static function ($sessions) use (&$files, &$rowResults): void {
                foreach ($sessions as $session) {
                    try {
                        SessionFiles::delete($session);
                    } catch (Throwable $exception) {
                        report($exception);

                        continue;
                    }

                    $rowResults += self::count(ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete());
                    $session->forceFill(['file_pruned_at' => Clock::now(), 'working_copy_path' => null])->save();
                    $files++;
                }
            });

        $sessions = 0;
        $batches = 0;
        $records = 0;
        $history = ImportsConfig::historyRetention();

        if ($history !== null) {
            $cutoff = $now->sub($history);

            ImportModels::session()::query()
                ->withTrashed()
                ->whereNotIn('status', $running)
                ->where('created_at', '<', $cutoff)
                ->chunkById(200, static function ($chunk) use (&$sessions, &$batches, &$records, &$rowResults, $now): void {
                    foreach ($chunk as $session) {
                        $batch = $session->batch;

                        if ($batch !== null && $batch->undo_until !== null && $batch->undo_until->greaterThan($now)) {
                            continue;
                        }

                        do {
                            $deleted = self::count(ImportModels::record()::query()->where('session_id', $session->getKey())->limit(1000)->delete());
                            $records += $deleted;
                        } while ($deleted >= 1000);

                        $rowResults += self::count(ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete());
                        $batches += self::count(ImportModels::batch()::query()->where('session_id', $session->getKey())->delete());

                        try {
                            SessionFiles::delete($session);
                        } catch (Throwable $exception) {
                            report($exception);
                        }

                        $session->forceDelete();
                        $sessions++;
                    }
                });
        }

        $report = new PruneReport($files, $rowResults, $sessions, $batches, $records);
        Event::dispatch(new ImportsPruned($report));

        return $report;
    }

    private static function count(mixed $deleted): int
    {
        return is_int($deleted) ? $deleted : 0;
    }
}
