<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ImportStateException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 409;

    public static function wrongStatus(string $status): self
    {
        return new self(__('imports::exceptions.wrong_status', ['status' => $status]));
    }

    public static function stalePreview(): self
    {
        return new self(__('imports::exceptions.stale_preview'));
    }

    public static function expired(): self
    {
        return new self(__('imports::exceptions.expired'));
    }

    public static function filePruned(): self
    {
        return new self(__('imports::exceptions.file_pruned'));
    }
}
