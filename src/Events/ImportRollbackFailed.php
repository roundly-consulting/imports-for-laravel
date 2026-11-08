<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * Undo of a batch stopped on an infrastructure error; it can be retried.
 */
final readonly class ImportRollbackFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public string $reason,
    ) {}
}
