<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Exceptions;

use RoundlyConsulting\Imports\Enums\RejectionReason;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The file (or archive) cannot be imported at all. The message is translated and says what to do
 * ("save as CSV UTF-8"); `reason` and `context` are for code.
 */
final class ImportFileRejectedException extends ImportsException implements HttpExceptionInterface
{
    use RendersAsHttp;

    private const int STATUS = 422;

    /**
     * @param  array<string, string|int>  $context
     */
    public function __construct(public readonly RejectionReason $reason, public readonly array $context = [])
    {
        parent::__construct(__('imports::rejections.'.$reason->value, $context));
    }

    /**
     * @param  array<string, string|int>  $context
     */
    public static function because(RejectionReason $reason, array $context = []): self
    {
        return new self($reason, $context);
    }
}
