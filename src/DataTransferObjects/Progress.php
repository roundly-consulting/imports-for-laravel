<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * How far a background preview, commit or rollback got. `phase` is `preview`, `commit` or
 * `rollback`.
 */
final readonly class Progress
{
    public function __construct(
        public int $processed,
        public int $total,
        public int $percent,
        public string $phase,
    ) {}
}
