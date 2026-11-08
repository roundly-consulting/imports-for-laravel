<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * One error on one cell (or the row, when `field` is null), named the way a user finds it: the
 * spreadsheet column letter and the row number.
 */
final readonly class CellError
{
    public function __construct(
        public ?string $field,
        public ?int $column,
        public ?string $columnLetter,
        public string $code,
        public string $message,
        public ?string $value,
    ) {}
}
