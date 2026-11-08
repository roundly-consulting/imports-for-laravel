<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * The preview could not finish (an unreadable file, an infrastructure error).
 */
final readonly class ImportPreviewFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public string $reason,
    ) {}
}
