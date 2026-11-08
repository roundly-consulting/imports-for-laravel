<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Mapping;

use RoundlyConsulting\Imports\Enums\MappingSource;

/**
 * The field a column was matched to, how sure the match is and where it came from.
 *
 * @internal
 */
final readonly class SuggestionPick
{
    public function __construct(
        public string $field,
        public float $confidence,
        public MappingSource $source,
    ) {}
}
