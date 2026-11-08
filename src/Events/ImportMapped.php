<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * A session got its column mapping (applied by hand or from a saved mapping).
 */
final readonly class ImportMapped implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
    ) {}
}
