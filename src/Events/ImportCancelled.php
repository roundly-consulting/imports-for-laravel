<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * A running commit was cancelled; rows written so far stay and can be undone.
 */
final readonly class ImportCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
    ) {}
}
