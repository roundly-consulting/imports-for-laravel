<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TooManySavedMappingsException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 422;

    public static function limit(int $limit): self
    {
        return new self(__('imports::exceptions.too_many_mappings', ['limit' => $limit]));
    }

    public static function tooLarge(): self
    {
        return new self(__('imports::exceptions.mappings_too_large'));
    }

    public static function invalidName(): self
    {
        return new self(__('imports::exceptions.mapping_name'));
    }

    public static function disabled(): self
    {
        return new self(__('imports::exceptions.mappings_disabled'));
    }
}
