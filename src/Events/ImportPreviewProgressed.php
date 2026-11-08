<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * A queued preview validated another batch of rows.
 */
final readonly class ImportPreviewProgressed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public int $processed,
        public int $total,
    ) {}
}
