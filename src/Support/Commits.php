<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Imports\Actions\Commit\FailImportAction;
use RoundlyConsulting\Imports\Actions\Commit\FinishImportAction;
use RoundlyConsulting\Imports\Jobs\CommitChunkJob;
use RoundlyConsulting\Imports\Models\ImportBatch;
use Throwable;

/**
 * Dispatches a commit run: one Laravel job batch holding ONE sequential chain of chunk jobs
 * (deterministic order, a monotone resume point). The callbacks capture ids only; `then` checks
 * `cancelled()` because a cancelled batch whose remaining jobs returned early still reaches zero
 * pending jobs.
 *
 * @internal
 */
final class Commits
{
    /**
     * Dispatch, and when the queue refuses the batch, fail the commit at once (`dispatch_failed`,
     * `ImportFailed`, the lock released) instead of leaving it `committing` until the sweep.
     */
    public static function dispatchOrFail(ImportBatch $batch, int $from): void
    {
        try {
            self::dispatch($batch, $from);
        } catch (Throwable $exception) {
            app(FailImportAction::class)->execute(Keys::of($batch), 'dispatch_failed', $exception->getMessage());

            throw $exception;
        }
    }

    private static function dispatch(ImportBatch $batch, int $from): void
    {
        $batchId = Keys::of($batch);

        if ($from >= $batch->total_chunks) {
            app(FinishImportAction::class)->execute($batchId, false);

            return;
        }

        $chain = [];

        for ($index = $from; $index < $batch->total_chunks; $index++) {
            $chain[] = new CommitChunkJob($batchId, $index, $index === $from);
        }

        $pending = Bus::batch([$chain])
            ->name('imports:commit:'.$batchId)
            ->then(static function (Batch $laravel) use ($batchId): void {
                app(FinishImportAction::class)->execute($batchId, $laravel->cancelled());
            })
            ->catch(static function (Batch $laravel, ?Throwable $exception) use ($batchId): void {
                app(FailImportAction::class)->execute($batchId, 'chunk_failed', $exception?->getMessage());
            });

        if (($connection = Queues::connection()) !== null) {
            $pending->onConnection($connection);
        }

        if (($queue = Queues::queue()) !== null) {
            $pending->onQueue($queue);
        }

        $laravel = $pending->dispatch();

        ImportModels::batch()::query()->whereKey($batchId)->update(['laravel_batch_id' => $laravel->id]);
    }
}
