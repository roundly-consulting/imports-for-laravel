<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSummary;

/**
 * A commit stopped; it can be resumed (or undone).
 */
final readonly class ImportFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public string $reason,
        public ImportSummary $summary,
    ) {}
}
