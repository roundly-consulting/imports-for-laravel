<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;

/**
 * The one definition of "can this be committed / undone now": the commit and rollback actions
 * refuse by these rules, and the handles and resources report them, so a UI never offers a button
 * the action then refuses (or hides one it would accept, such as retrying a failed undo).
 *
 * @internal
 */
final class Abilities
{
    public static function canCommit(ImportSession $session): bool
    {
        return $session->status === SessionStatus::Previewed && ! self::expired($session);
    }

    public static function expired(ImportSession $session): bool
    {
        return $session->expires_at !== null && $session->expires_at->lessThan(Clock::now());
    }

    public static function canRollback(ImportBatch $batch): bool
    {
        return self::rollbackStatusAllows($batch) && self::undoWindowOpen($batch);
    }

    /**
     * A finished run (completed, failed or cancelled) with no undo yet, or one whose undo failed
     * and may be started again.
     */
    public static function rollbackStatusAllows(ImportBatch $batch): bool
    {
        return in_array($batch->status, [BatchStatus::Completed, BatchStatus::Failed, BatchStatus::Cancelled], true)
            && ($batch->rollback_status === null || $batch->rollback_status === RollbackStatus::RollbackFailed);
    }

    public static function undoWindowOpen(ImportBatch $batch): bool
    {
        return $batch->undo_until !== null && Clock::now()->lessThan($batch->undo_until);
    }
}
