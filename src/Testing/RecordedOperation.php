<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Testing;

/**
 * One operation the fake recorded: its name, what it was asked with and what it returned.
 */
final readonly class RecordedOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $operation,
        public array $context,
        public mixed $result,
    ) {}
}
