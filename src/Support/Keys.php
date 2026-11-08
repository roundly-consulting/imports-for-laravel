<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A saved package model's key, typed (`getKey()` is `mixed` to static analysis).
 *
 * @internal
 */
final class Keys
{
    public static function of(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : throw new LogicException(sprintf('%s has no key yet.', class_basename($model)));
    }

    /**
     * Whether an id from outside (a route, a host call) can be a key of the package's own tables
     * (`imports.primary_key_type`). Checked before querying: PostgreSQL refuses `'abc'` for a
     * bigint or uuid column with an SQL error, where a lookup must simply find nothing.
     */
    public static function accepts(int|string $id): bool
    {
        return KeyTypes::fits($id, ImportsConfig::primaryKeyType());
    }
}
