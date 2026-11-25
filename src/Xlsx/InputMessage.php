<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

/**
 * The input message Excel shows when a cell is selected (title ≤ 32, prompt ≤ 255 characters;
 * longer text is cut).
 */
final readonly class InputMessage
{
    public function __construct(
        public string $title,
        public string $prompt,
    ) {}
}
