<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * One column (0-based index) mapped to one field.
 */
final readonly class MappedColumn
{
    public function __construct(
        public int $index,
        public string $field,
    ) {}
}
