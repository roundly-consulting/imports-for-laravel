<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * teams' TeamScope, in miniature: every query on a scoped model is limited to the current team
 * and throws without one (`teams.scoping.without_context = throw`).
 */
final class ContextTeamScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('team_id'), ContextTeams::require());
    }
}
