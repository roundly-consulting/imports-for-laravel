<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The actor a handle acts as. It is stored as a morph, so it must be an Eloquent model: anything
 * else is refused rather than silently acting as nobody.
 *
 * @internal
 */
final class Actors
{
    public static function of(Authenticatable|Model|null $actor): ?Model
    {
        if ($actor !== null && ! $actor instanceof Model) {
            throw new LogicException(sprintf('The actor must be an Eloquent model: it is stored as a morph. [%s] given.', $actor::class));
        }

        return $actor;
    }
}
