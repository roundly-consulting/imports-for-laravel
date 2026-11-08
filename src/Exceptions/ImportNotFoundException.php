<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ImportNotFoundException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 404;

    public static function session(): self
    {
        return new self(__('imports::exceptions.not_found'));
    }

    public static function batch(): self
    {
        return new self(__('imports::exceptions.batch_not_found'));
    }
}
