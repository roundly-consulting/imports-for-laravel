<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;
use RoundlyConsulting\Imports\DataTransferObjects\RollbackReport;

/**
 * Undo of a batch finished.
 */
final readonly class ImportRolledBack implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public RollbackReport $report,
    ) {}
}
