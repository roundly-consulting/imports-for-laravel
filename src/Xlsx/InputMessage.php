<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

/**
 * The input message Excel shows when a template cell is selected.
 *
 * @internal
 */
final readonly class InputMessage
{
    public function __construct(
        public string $title,
        public string $prompt,
    ) {}
}
