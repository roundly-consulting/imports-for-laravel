<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A mapping that cannot be saved: saved mappings are off, the name is not 1–60 characters, or the
 * columns name no field, an unknown field or one field twice.
 */
final class InvalidSavedMappingException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 422;

    public static function disabled(): self
    {
        return new self(__('imports::exceptions.mappings_disabled'));
    }

    public static function invalidName(): self
    {
        return new self(__('imports::exceptions.mapping_name'));
    }

    public static function unknownField(string $field): self
    {
        return new self(__('imports::exceptions.mapping_unknown_field', ['field' => $field]));
    }

    public static function fieldTwice(string $field): self
    {
        return new self(__('imports::exceptions.mapping_field_twice', ['field' => $field]));
    }

    public static function empty(): self
    {
        return new self(__('imports::exceptions.mapping_empty'));
    }
}
