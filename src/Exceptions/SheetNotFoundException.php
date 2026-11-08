<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SheetNotFoundException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 422;

    /**
     * @param  list<string>  $available
     */
    public function __construct(string $message, public readonly array $available)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $available
     */
    public static function named(string|int $sheet, array $available): self
    {
        return new self(__('imports::exceptions.sheet_not_found', [
            'sheet' => (string) $sheet,
            'sheets' => implode(', ', $available),
        ]), $available);
    }
}
