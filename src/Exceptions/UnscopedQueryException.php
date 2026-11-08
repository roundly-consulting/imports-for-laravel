<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

/**
 * The owner scope cannot constrain a host query: the package refuses to run it unscoped.
 * Operator-facing, so English.
 */
final class UnscopedQueryException extends ImportsException
{
    public static function forModel(string $importer, string $model, string $why): self
    {
        return new self(sprintf('Importer [%s]: queries on %s cannot be kept inside the import owner (%s). Declare an Ownership for it.', $importer, class_basename($model), $why));
    }
}
