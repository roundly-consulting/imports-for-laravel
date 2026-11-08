<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Support\Facades\Bus;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Models\ImportBatch;

/**
 * The end of a commit run, shared by finish, fail and cancel: statuses, `finished_at`,
 * `undo_until` (failed and cancelled batches are undoable too), the lock released and — unless it
 * completed — the Laravel job batch cancelled, so no leftover chunk job runs.
 *
 * @internal
 */
final class Batches
{
    /**
     * @param  array<string, string|null>|null  $failure
     */
    public static function close(ImportBatch $batch, BatchStatus $status, ?array $failure = null): void
    {
        $now = Clock::now();

        if ($status !== BatchStatus::Completed) {
            self::cancel($batch->laravel_batch_id);
        }

        $batch->forceFill([
            'status' => $status,
            'failure' => $failure,
            'finished_at' => $now,
            'undo_until' => $now->add(ImportsConfig::undoWindow()),
        ])->save();

        ImportModels::session()::query()->whereKey($batch->session_id)->update([
            'status' => match ($status) {
                BatchStatus::Completed => SessionStatus::Completed->value,
                BatchStatus::Cancelled => SessionStatus::Cancelled->value,
                default => SessionStatus::Failed->value,
            },
            'lock_key' => null,
            'last_progress_at' => Clock::format($now),
            'updated_at' => Clock::format($now),
        ]);
    }

    /**
     * Cancel a Laravel job batch so its remaining jobs return early. A batch whose `job_batches`
     * row is gone (pruned, or never stored) has nothing left to cancel.
     */
    public static function cancel(?string $laravelBatchId): void
    {
        if ($laravelBatchId === null) {
            return;
        }

        $laravel = Bus::findBatch($laravelBatchId);

        if ($laravel !== null && ! $laravel->cancelled()) {
            $laravel->cancel();
        }
    }
}
