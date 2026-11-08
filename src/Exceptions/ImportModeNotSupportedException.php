<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ImportModeNotSupportedException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 422;

    public static function mode(string $mode, string $importer): self
    {
        return new self(__('imports::exceptions.mode_not_supported', ['mode' => $mode, 'importer' => $importer]));
    }
}
