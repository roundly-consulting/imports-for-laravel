<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Exceptions\UnscopedQueryException;

/**
 * The one place the pipeline starts a query on a host model — references, natural-key matches,
 * rollback loads, ownership checks — and every such query is owner-constrained. An arch test pins
 * that no other class calls `::query()` on a host model.
 *
 * @internal
 */
final class Scoped
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function query(string $model, Model $owner, Ownership $rule, OwnerScope $scope, bool $withTrashed = false): Builder
    {
        if ($rule->kind === 'inherit') {
            throw UnscopedQueryException::forModel('?', $model, 'an inherited rule was never resolved');
        }

        $query = $model::query();

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $scope->constrain($query, $owner, $rule);
    }
}
