<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Exceptions\ImportUnauthorizedException;

/**
 * The importer's authorize() callback, asked at upload (before the file is read), at commit and
 * again when each run of the batch starts, at rollback, and for every read.
 *
 * @internal
 */
final class Access
{
    public static function check(ImportDefinition $definition, ?Model $actor, Model $owner, ImportOperation $operation): void
    {
        if (! self::allows($definition, $actor, $owner, $operation)) {
            throw ImportUnauthorizedException::operation($operation->value);
        }
    }

    public static function allows(ImportDefinition $definition, ?Model $actor, Model $owner, ImportOperation $operation): bool
    {
        return $definition->authorize($actor instanceof Authenticatable ? $actor : null, $owner, $operation);
    }
}
