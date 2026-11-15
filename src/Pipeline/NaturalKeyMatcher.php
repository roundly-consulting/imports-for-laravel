<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Ownership;

/**
 * Finds existing records for a window of rows, one query per natural-key candidate, inside the
 * owner. Results are grouped by the candidate's canonical key string.
 *
 * @internal
 */
final class NaturalKeyMatcher
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<int, non-empty-list<KeyPart>>  $candidates  candidate index => its parts
     * @param  array<int, list<array<string, string>>>  $keys  candidate index => the key tuples (column => canonical value)
     * @return array<int, array<string, list<Model>>> candidate index => key string => records
     */
    public static function find(string $model, array $candidates, bool $caseInsensitive, array $keys, Model $owner, Ownership $rule, OwnerScope $scope): array
    {
        $found = [];

        foreach ($keys as $candidate => $tuples) {
            $parts = $candidates[$candidate] ?? [];

            if ($tuples === [] || $parts === []) {
                continue;
            }

            $folded = [];

            foreach ($parts as $part) {
                // A reference compares a record key: exact, and LOWER() on an integer column fails.
                $folded[$part->column] = $caseInsensitive && ! $part->reference;
            }

            foreach (array_chunk($tuples, 250) as $chunk) {
                // Soft-deleted records too: the host's unique index still covers them, so a row
                // that "creates" one of their keys would fail at commit. The pipeline flags it.
                $query = Scoped::query($model, $owner, $rule, $scope, true);

                if (count($parts) === 1) {
                    $column = $parts[0]->column;
                    ReferenceResolver::whereIn($query, $column, array_values(array_unique(array_column($chunk, $column))), $folded[$column]);
                } else {
                    $query->where(static function (Builder $outer) use ($chunk, $folded): void {
                        foreach ($chunk as $tuple) {
                            $outer->orWhere(static function (Builder $inner) use ($tuple, $folded): void {
                                foreach ($tuple as $column => $value) {
                                    ReferenceResolver::whereIn($inner, $column, [$value], $folded[$column] ?? false);
                                }
                            });
                        }
                    });
                }

                foreach ($query->get() as $record) {
                    $key = self::keyOf($record, $parts, $caseInsensitive);

                    if ($key !== null) {
                        $found[$candidate][$key][] = $record;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<KeyPart>  $parts
     */
    public static function keyOf(Model $record, array $parts, bool $caseInsensitive): ?string
    {
        $values = [];

        foreach ($parts as $part) {
            $value = KeyValues::of($record->getAttribute($part->column), $caseInsensitive && ! $part->reference);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return implode("\x1f", $values);
    }
}
