<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSummary;

/**
 * One commit chunk was written (after its transaction committed).
 */
final readonly class ImportChunkProcessed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public int $chunk,
        public int $rows,
        public ImportSummary $summary,
    ) {}
}
