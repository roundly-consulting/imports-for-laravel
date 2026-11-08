<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * A host record by its stored morph pair — no query, so a deleted record keeps rendering.
 */
final readonly class RecordReference
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}
}
