<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Contracts\Database\Query\Builder;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;

/**
 * Which ledger rows still stand for an imported record: never undone, or undone without removing
 * it — a record undo kept (changed since the import, kept by the hook) or failed to remove is still
 * the import's record, so it still counts for `wasImported()` and the file duplicate check.
 *
 * @internal
 */
final class StillImported
{
    /**
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function constrain(Builder $query): Builder
    {
        $query->where(static function (Builder $ledger): void {
            $ledger->whereNull('rolled_back_at')
                ->orWhereNull('rollback_outcome')
                ->orWhereNotIn('rollback_outcome', [RollbackOutcome::Deleted->value, RollbackOutcome::Handled->value, RollbackOutcome::Missing->value]);
        });

        return $query;
    }
}
