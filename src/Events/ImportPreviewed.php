<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;

/**
 * Every row was validated; the preview is ready to review.
 */
final readonly class ImportPreviewed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
    ) {}
}
