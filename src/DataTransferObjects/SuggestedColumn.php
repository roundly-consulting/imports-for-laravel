<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\MappingSource;

/**
 * One column of a mapping suggestion: the field it maps to (or null), how sure the match is
 * (0–1) and where it came from. Fuzzy matches are flagged for review.
 */
final readonly class SuggestedColumn
{
    public function __construct(
        public int $index,
        public string $header,
        public ?string $field,
        public float $confidence,
        public ?MappingSource $source,
    ) {}

    public function isFuzzy(): bool
    {
        return $this->source === MappingSource::Fuzzy;
    }
}
