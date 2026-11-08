<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * Undo of a batch started.
 */
final readonly class ImportRollbackStarted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
    ) {}
}
