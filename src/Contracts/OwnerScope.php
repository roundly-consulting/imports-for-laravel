<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Ownership;

/**
 * Keeps every lookup and write of an import inside its owner. The default applies each importer's
 * declared Ownership; the teams recipe runs everything inside `Teams::runAs($owner, …)`.
 */
interface OwnerScope
{
    /**
     * Run $callback inside $owner's ambient context (teams recipe: Teams::runAs). Every background
     * entry point of the pipeline runs its body through here.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Model $owner, Closure $callback): mixed;

    /**
     * Constrain a query on a host model to $owner; throw UnscopedQueryException when it cannot.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, Model $owner, Ownership $rule): Builder;

    /**
     * Whether $record is inside $owner — checked after every create/update, inside the row's
     * savepoint.
     */
    public function owns(Model $record, Model $owner, Ownership $rule): bool;
}
