<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Rollback;

use Illuminate\Bus\Batch;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportRollbackStarted;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Exceptions\UndoWindowClosedException;
use RoundlyConsulting\Imports\Jobs\RollbackChunkJob;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportLock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\KeyTypes;
use RoundlyConsulting\Imports\Support\Queues;
use Throwable;

/**
 * Undo a batch within its window: the records it created that are unchanged since the import are
 * removed (or handed to the importer's rollback hook); changed ones are kept and listed; updates
 * and linked records are never touched. Small undos run inline, larger ones as a job batch over
 * keyset ranges of ledger ids. A second rollback is a no-op.
 */
final readonly class RollbackImportAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private Container $container,
    ) {}

    public function execute(ImportBatch $batch, ?Model $actor): ImportBatch
    {
        $batch->refresh();
        $session = $batch->session;
        Access::check($this->registry->find($session->importer), $actor, Audits::owner($session), ImportOperation::Rollback);
        KeyTypes::assert($actor, 'actor');

        if ($batch->rollback_status === RollbackStatus::RolledBack) {
            return $batch;
        }

        if ($batch->rollback_status === RollbackStatus::RollingBack || ! in_array($batch->status, [BatchStatus::Completed, BatchStatus::Failed, BatchStatus::Cancelled], true)) {
            throw ImportStateException::wrongStatus($batch->rollback_status->value ?? $batch->status->value);
        }

        if ($batch->undo_until === null || ! Clock::now()->lessThan($batch->undo_until)) {
            throw UndoWindowClosedException::closed();
        }

        ImportLock::acquire($session, [...SessionStatus::undoable(), SessionStatus::RollbackFailed], SessionStatus::RollingBack);

        $batch->forceFill([
            'rollback_status' => RollbackStatus::RollingBack,
            'rollback_actor_type' => $actor?->getMorphClass(),
            'rollback_actor_id' => $actor?->getKey(),
        ])->save();

        Event::dispatch(new ImportRollbackStarted(Audits::for($session, $batch, $actor)));

        $ids = ImportModels::record()::query()
            ->where('batch_id', $batch->getKey())
            ->where('action', RecordAction::Created->value)
            ->whereNull('rolled_back_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $ranges = [];

        foreach (array_chunk($ids, max(1, ImportsConfig::chunkSize())) as $chunk) {
            $first = $chunk[0];
            $last = $chunk[count($chunk) - 1];

            if (is_int($first) || is_string($first)) {
                $ranges[] = [$first, is_int($last) || is_string($last) ? $last : $first];
            }
        }

        $batchId = Keys::of($batch);

        if (count($ids) <= ImportsConfig::syncThreshold() || ! ImportsConfig::queueEnabled()) {
            try {
                foreach ($ranges as [$from, $to]) {
                    $this->container->make(RollbackChunkAction::class)->execute($batchId, $from, $to);
                }
            } catch (Throwable $exception) {
                $this->container->make(FinishRollbackAction::class)->execute($batchId, mb_substr($exception->getMessage(), 0, 255));

                throw $exception;
            }

            $this->container->make(FinishRollbackAction::class)->execute($batchId);

            return $batch->refresh();
        }

        $pending = Bus::batch(array_map(static fn (array $range): RollbackChunkJob => new RollbackChunkJob($batchId, $range[0], $range[1]), $ranges))
            ->name('imports:rollback:'.$batchId)
            ->allowFailures()
            ->finally(static function (Batch $laravel) use ($batchId): void {
                app(FinishRollbackAction::class)->execute($batchId, $laravel->hasFailures() ? 'chunk_failed' : null);
            });

        if (($connection = Queues::connection()) !== null) {
            $pending->onConnection($connection);
        }

        if (($queue = Queues::queue()) !== null) {
            $pending->onQueue($queue);
        }

        $laravel = $pending->dispatch();
        $batch->forceFill(['rollback_batch_id' => $laravel->id])->save();

        return $batch->refresh();
    }
}
