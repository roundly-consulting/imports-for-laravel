<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

/**
 * How the writer lays out one sheet: a bold frozen header, per-column widths and number formats,
 * list validations and input messages (applied to rows 2…`$validationRows + 1`), visibility.
 *
 * @internal
 */
final readonly class SheetLayout
{
    /**
     * @param  array<int, float>  $widths  column index => width
     * @param  array<int, string>  $formats  column index => 'text'|'date'|'datetime'|'decimal:<scale>'
     * @param  array<int, string>  $lists  column index => inline list `"a,b"` or a defined name
     * @param  array<int, array{title: string, prompt: string}>  $prompts  column index => input message
     */
    public function __construct(
        public bool $boldHeader = false,
        public bool $freezeHeader = false,
        public array $widths = [],
        public array $formats = [],
        public array $lists = [],
        public array $prompts = [],
        public int $validationRows = 0,
        public bool $hidden = false,
    ) {}
}
