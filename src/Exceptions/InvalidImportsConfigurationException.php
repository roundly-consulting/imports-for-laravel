<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use SensitiveParameter;

/**
 * A configuration value the package cannot use. Operator-facing, so English, and always naming the
 * config key to fix.
 */
final class InvalidImportsConfigurationException extends ImportsException
{
    public static function invalidDuration(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a positive ISO-8601 duration such as P7D, [%s] given.', $key, self::describe($value)));
    }

    public static function invalidTable(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a table name of lowercase letters, digits and underscores, at most 30 characters, [%s] given.', $key, self::describe($value)));
    }

    public static function invalidValue(string $key, string $expected): self
    {
        return new self(sprintf('Configuration value [%s] must be %s.', $key, $expected));
    }

    public static function notAnImplementation(string $key, string $contract, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an instantiable class implementing or extending [%s], [%s] given.', $key, $contract, self::describe($value)));
    }

    public static function keyTypeMismatch(string $key, string $model, string $type): self
    {
        return new self(sprintf('The %s key does not match configuration value [%s] (%s). Set [%s] to the key type of %s.', $model, $key, $type, $key, $model));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_scalar($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }
}
