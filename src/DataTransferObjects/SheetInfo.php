<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * One worksheet of a workbook, for a sheet picker: `index` is its 1-based position among the
 * visible worksheets (what a user sees in the tab bar), `null` for a hidden sheet.
 */
final readonly class SheetInfo
{
    public function __construct(
        public string $name,
        public ?int $index,
        public bool $visible,
    ) {}
}
