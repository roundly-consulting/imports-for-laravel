<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;

/**
 * A session's mapping was saved under a name for its owner.
 */
final readonly class MappingSaved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public ImportAudit $audit,
        public SavedMapping $mapping,
    ) {}
}
