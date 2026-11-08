<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class UnknownImporterException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 404;

    public static function key(string $key): self
    {
        return new self(__('imports::exceptions.unknown_importer', ['importer' => $key]));
    }
}
