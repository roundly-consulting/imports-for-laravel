<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Testing;

/**
 * One operation the fake recorded: its name, what it was asked with, what it returned and the
 * action that ran it.
 */
final readonly class RecordedOperation
{
    /**
     * @param  array<string, mixed>  $context
     * @param  class-string|null  $action
     */
    public function __construct(
        public string $operation,
        public array $context,
        public mixed $result,
        public ?string $action = null,
    ) {}
}
