<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class UndoWindowClosedException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 409;

    public static function closed(): self
    {
        return new self(__('imports::exceptions.undo_window_closed'));
    }
}
