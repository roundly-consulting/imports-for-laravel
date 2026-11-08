<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * A file was uploaded and its session created.
 */
final readonly class ImportUploaded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
    ) {}
}
