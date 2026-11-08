<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Imports\Actions\Rollback\RollbackChunkAction;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * One keyset range of ledger ids, fixed when the rollback started (offset pages would skip rows
 * as they get rolled back). Each record runs in its own transaction and is idempotent.
 *
 * @internal
 */
final class RollbackChunkJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public int $timeout;

    public function __construct(
        public readonly int|string $importBatchId,
        public readonly int|string $fromId,
        public readonly int|string $toId,
    ) {
        $this->tries = ImportsConfig::tries();
        $this->backoff = ImportsConfig::backoff();
        $this->timeout = ImportsConfig::timeout();
    }

    public function handle(RollbackChunkAction $action): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $action->execute($this->importBatchId, $this->fromId, $this->toId);
    }
}
