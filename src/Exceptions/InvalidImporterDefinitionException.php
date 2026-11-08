<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

/**
 * An importer definition breaks a rule; the message names the importer and the rule.
 * Operator-facing, so English.
 */
final class InvalidImporterDefinitionException extends ImportsException
{
    public static function because(string $importer, string $rule): self
    {
        return new self(sprintf('Importer [%s] is invalid: %s', $importer, $rule));
    }
}
