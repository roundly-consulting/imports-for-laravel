<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * What `imports:sweep` did: uncommitted sessions expired, stuck commits and rollbacks failed and
 * unlocked, orphaned temp directories removed.
 */
final readonly class SweepReport
{
    public function __construct(
        public int $expired = 0,
        public int $failedCommits = 0,
        public int $failedRollbacks = 0,
        public int $tempDirectories = 0,
    ) {}
}
