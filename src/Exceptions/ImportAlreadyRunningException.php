<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ImportAlreadyRunningException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 409;

    public function __construct(string $message, public readonly ?string $runningSessionId = null)
    {
        parent::__construct($message);
    }

    public static function forSession(?string $runningSessionId): self
    {
        return new self(__('imports::exceptions.already_running'), $runningSessionId);
    }
}
