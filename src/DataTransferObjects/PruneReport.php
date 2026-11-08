<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * What `imports:prune` removed.
 */
final readonly class PruneReport
{
    public function __construct(
        public int $files = 0,
        public int $rowResults = 0,
        public int $sessions = 0,
        public int $batches = 0,
        public int $records = 0,
    ) {}
}
