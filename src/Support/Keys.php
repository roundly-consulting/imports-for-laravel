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
}
