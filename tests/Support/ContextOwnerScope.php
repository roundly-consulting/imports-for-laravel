<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;

/**
 * The TeamsOwnerScope recipe, against the context-teams fixture: every pipeline step runs inside
 * the import owner's team, so the scope and the write guard isolate it automatically.
 */
final class ContextOwnerScope implements OwnerScope
{
    public function run(Model $owner, Closure $callback): mixed
    {
        return ContextTeams::runAs($owner, $callback);
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
