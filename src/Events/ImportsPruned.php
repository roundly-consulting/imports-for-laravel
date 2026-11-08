<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Imports\DataTransferObjects\PruneReport;

/**
 * `imports:prune` removed old files, row results and (with history retention) old history.
 */
final readonly class ImportsPruned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public PruneReport $report,
    ) {}
}
