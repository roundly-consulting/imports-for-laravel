<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ImportUnauthorizedException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 403;

    public static function operation(string $operation): self
    {
        return new self(__('imports::exceptions.unauthorized', ['operation' => $operation]));
    }
}
