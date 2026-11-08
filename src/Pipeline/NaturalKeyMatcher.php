<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\NaturalKey;
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
     * @param  array<int, list<array<string, string>>>  $keys  candidate index => the key tuples (field => canonical value)
     * @return array<int, array<string, list<Model>>> candidate index => key string => records
     */
    public static function find(string $model, NaturalKey $naturalKey, array $keys, Model $owner, Ownership $rule, OwnerScope $scope): array
    {
        $found = [];

        foreach ($keys as $candidate => $tuples) {
            $fields = $naturalKey->candidates[$candidate] ?? [];

            if ($tuples === [] || $fields === []) {
                continue;
            }

            foreach (array_chunk($tuples, 250) as $chunk) {
                // Soft-deleted records too: the host's unique index still covers them, so a row
                // that "creates" one of their keys would fail at commit. The pipeline flags it.
                $query = Scoped::query($model, $owner, $rule, $scope, true);

                if (count($fields) === 1) {
                    ReferenceResolver::whereIn($query, $fields[0], array_values(array_unique(array_column($chunk, $fields[0]))), $naturalKey->caseInsensitive);
                } else {
                    $query->where(static function (Builder $outer) use ($chunk, $naturalKey): void {
                        foreach ($chunk as $tuple) {
                            $outer->orWhere(static function (Builder $inner) use ($tuple, $naturalKey): void {
                                foreach ($tuple as $column => $value) {
                                    ReferenceResolver::whereIn($inner, $column, [$value], $naturalKey->caseInsensitive);
                                }
                            });
                        }
                    });
                }

                foreach ($query->get() as $record) {
                    $key = self::keyOf($record, $fields, $naturalKey->caseInsensitive);

                    if ($key !== null) {
                        $found[$candidate][$key][] = $record;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $fields
     */
    public static function keyOf(Model $record, array $fields, bool $caseInsensitive): ?string
    {
        $parts = [];

        foreach ($fields as $field) {
            $value = KeyValues::of($record->getAttribute($field), $caseInsensitive);

            if ($value === null) {
                return null;
            }

            $parts[] = $value;
        }

        return implode("\x1f", $parts);
    }
}
