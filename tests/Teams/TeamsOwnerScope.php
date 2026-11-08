<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Teams;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;

/**
 * The teams recipe (docs: owner context): every step of an import runs inside
 * `Teams::runAs($owner)`, so teams' TeamScope and TeamWriteGuard isolate each Eloquent read and
 * write — and the import owner's team replaces whatever team a queued job's dispatcher had.
 * Declared Ownership rules still apply on top.
 */
final class TeamsOwnerScope implements OwnerScope
{
    public function run(Model $owner, Closure $callback): mixed
    {
        if (! $owner instanceof Team) {
            throw new InvalidArgumentException('Imports owned by teams need a team as their owner.');
        }

        return Teams::runAs($owner, static fn (): mixed => $callback());
    }

    public function constrain(Builder $query, Model $owner, Ownership $rule): Builder
    {
        return (new ExplicitOwnerScope)->constrain($query, $owner, $rule);
    }

    public function owns(Model $record, Model $owner, Ownership $rule): bool
    {
        return (new ExplicitOwnerScope)->owns($record, $owner, $rule);
    }
}
