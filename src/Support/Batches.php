<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Models\ImportBatch;

/**
 * The end of a commit run, shared by finish, fail and cancel: statuses, `finished_at`,
 * `undo_until` (failed and cancelled batches are undoable too) and the lock released.
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
}
