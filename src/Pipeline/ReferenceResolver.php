<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\PackageToolkit\Support\RawExpression;

/**
 * Resolves reference values to models, inside the owner, one `whereIn` per lookup column per
 * window: the first column with exactly one match wins; several matches in that column are
 * ambiguous; no column matching is unknown.
 *
 * @internal
 */
final class ReferenceResolver
{
    public const string UNKNOWN = 'unknown';

    public const string AMBIGUOUS = 'ambiguous';

    /**
     * @param  list<string>  $values
     * @return array<string, Model|self::UNKNOWN|self::AMBIGUOUS> keyed by the (case-folded) value
     */
    public static function resolve(Field $field, array $values, Model $owner, Ownership $rule, OwnerScope $scope): array
    {
        /** @var class-string<Model> $model */
        $model = (string) $field->target;
        $results = [];
        $remaining = [];

        foreach ($values as $value) {
            $remaining[$field->caseInsensitive ? mb_strtolower($value) : $value] = true;
        }

        foreach ($field->lookup as $column) {
            if ($remaining === []) {
                break;
            }

            $found = [];

            foreach (array_chunk(array_keys($remaining), 500) as $chunk) {
                $query = Scoped::query($model, $owner, $rule, $scope);
                self::whereIn($query, $column, array_map('strval', $chunk), $field->caseInsensitive);

                foreach ($query->get() as $record) {
                    $key = KeyValues::of($record->getAttribute($column), $field->caseInsensitive);

                    if ($key !== null && isset($remaining[$key])) {
                        $found[$key][] = $record;
                    }
                }
            }

            foreach ($found as $key => $records) {
                $results[$key] = count($records) === 1 ? $records[0] : self::AMBIGUOUS;
                unset($remaining[$key]);
            }
        }

        foreach (array_keys($remaining) as $key) {
            $results[(string) $key] = self::UNKNOWN;
        }

        return $results;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $values
     */
    public static function whereIn(Builder $query, string $column, array $values, bool $caseInsensitive): void
    {
        $qualified = $query->getModel()->qualifyColumn($column);

        if ($caseInsensitive) {
            $wrapped = $query->getQuery()->getGrammar()->wrap($qualified);
            // The column is developer-declared and grammar-wrapped; the values are bound.
            $query->whereRaw(new RawExpression('LOWER('.$wrapped.') in ('.implode(', ', array_fill(0, count($values), '?')).')'), $values);

            return;
        }

        $query->whereIn($qualified, $values);
    }
}
