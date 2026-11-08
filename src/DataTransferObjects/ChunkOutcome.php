<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * What one commit chunk did: the running summary (null when another delivery already did the
 * chunk) and how many rows it covered.
 *
 * @internal
 */
final readonly class ChunkOutcome
{
    public function __construct(
        public ?ImportSummary $summary,
        public int $rows,
    ) {}
}
