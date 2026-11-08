<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * What a commit did. `linked` counts rows whose create callback returned an existing record (never
 * removed by undo); `reclassified` counts rows whose classification changed between the preview
 * and the commit (e.g. their match was deleted meanwhile).
 */
final readonly class ImportSummary
{
    public function __construct(
        public int $created = 0,
        public int $linked = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
        public int $reclassified = 0,
        public int $duplicates = 0,
        public int $invalid = 0,
        public ?int $durationSeconds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $counts
     */
    public static function fromArray(array $counts, ?int $duration = null): self
    {
        $int = static fn (string $key): int => is_numeric($counts[$key] ?? null) ? (int) $counts[$key] : 0;

        return new self($int('created'), $int('linked'), $int('updated'), $int('skipped'), $int('failed'), $int('reclassified'), $int('duplicates'), $int('invalid'), $duration);
    }
}
