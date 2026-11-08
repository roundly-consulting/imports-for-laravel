<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A mapping the session cannot use; `errors` is keyed by column (or `mapping`) like a validation
 * error bag, so a UI can mark the offending columns.
 */
final class InvalidMappingException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 422;

    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(__('imports::exceptions.invalid_mapping'));
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self($errors);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage(), 'errors' => $this->errors], self::STATUS);
    }
}
