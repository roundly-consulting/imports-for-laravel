<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

/**
 * One worksheet of a workbook: its name, whether it is visible and the package part it lives in.
 *
 * @internal
 */
final readonly class SheetPart
{
    public function __construct(
        public string $name,
        public bool $visible,
        public string $part,
    ) {}
}
