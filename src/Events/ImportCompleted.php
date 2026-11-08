<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSummary;

/**
 * Every chunk of a commit ran.
 */
final readonly class ImportCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public ImportSummary $summary,
    ) {}
}
