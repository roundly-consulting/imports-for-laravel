<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

/**
 * Thrown by an importer callback to fail one row with a message the user sees (translate it in
 * the host). The rest of the chunk carries on.
 */
final class ImportRowException extends ImportsException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
