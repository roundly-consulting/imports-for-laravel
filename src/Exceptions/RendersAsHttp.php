<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Lets Laravel's handler answer with the right status and the (translated) message — no global
 * rendering is configured; the exception only says what it is.
 *
 * @phpstan-require-implements HttpExceptionInterface
 */
trait RendersAsHttp
{
    public function getStatusCode(): int
    {
        return self::STATUS;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
