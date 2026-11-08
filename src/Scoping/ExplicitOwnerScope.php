<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Scoping;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Exceptions\UnscopedQueryException;

/**
 * The default owner scope: applies the Ownership each importer declares — a column, a belongs-to
 * relation, a morph, the importer's own closures, or the explicit global opt-out. No ambient
 * context, so `run()` calls straight through.
 */
final class ExplicitOwnerScope implements OwnerScope
{
    public function run(Model $owner, Closure $callback): mixed
    {
        return $callback();
    }

    public function constrain(Builder $query, Model $owner, Ownership $rule): Builder
    {
        $model = $query->getModel();

        match ($rule->kind) {
            'column' => $query->where($model->qualifyColumn((string) $rule->name), $owner->getKey()),
            'belongsTo' => $query->whereBelongsTo($owner, (string) $rule->name),
            'morph' => $query->where($model->qualifyColumn($rule->name.'_type'), $owner->getMorphClass())
                ->where($model->qualifyColumn($rule->name.'_id'), $owner->getKey()),
            // Nested: a host closure ending in `orWhere` must not swallow the caller's own wheres.
            'using' => $rule->query === null ? null : $query->where(static function (Builder $nested) use ($rule, $owner): void {
                ($rule->query)($nested, $owner);
            }),
            'global' => null,
            default => throw UnscopedQueryException::forModel('?', $model::class, 'the rule is '.$rule->describe()),
        };

        return $query;
    }

    public function owns(Model $record, Model $owner, Ownership $rule): bool
    {
        return match ($rule->kind) {
            'column' => self::same($record->getAttribute((string) $rule->name), $owner->getKey()),
            'belongsTo' => self::ownsThrough($record, $owner, (string) $rule->name),
            'morph' => $record->getAttribute($rule->name.'_type') === $owner->getMorphClass()
                && self::same($record->getAttribute($rule->name.'_id'), $owner->getKey()),
            'using' => $rule->owns !== null && ($rule->owns)($record, $owner) === true,
            'global' => true,
            default => false,
        };
    }

    private static function ownsThrough(Model $record, Model $owner, string $relation): bool
    {
        if (! method_exists($record, $relation)) {
            return false;
        }

        $belongsTo = $record->{$relation}();

        return $belongsTo instanceof BelongsTo
            && $belongsTo->getRelated() instanceof $owner
            && self::same($record->getAttribute($belongsTo->getForeignKeyName()), $owner->getAttribute($belongsTo->getOwnerKeyName()));
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return $a !== null && $b !== null && is_scalar($a) && is_scalar($b) && (string) $a === (string) $b;
    }
}
