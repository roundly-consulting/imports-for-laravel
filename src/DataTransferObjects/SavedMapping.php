<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * A mapping saved per owner and importer, keyed by normalised header — so reordered columns still
 * map — and auto-applied to a file whose header row has the same signature.
 */
final readonly class SavedMapping
{
    /**
     * @param  array<string, string>  $columns  normalised header => field
     */
    public function __construct(
        public string $name,
        public string $importer,
        public array $columns,
        public string $signature,
        public ?string $delimiter = null,
        public ?string $sheet = null,
        public int $headerRow = 1,
        public ?string $updatedAt = null,
    ) {}
}
