<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Imports\Actions\Commit\ProcessChunkAction;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * One commit chunk, part of the batch's single sequential chain. Ids only; returns early when the
 * batch was cancelled. A retry after a crash or redeploy is safe: the chunk claims the batch row
 * and does nothing if the chunk already committed.
 *
 * @internal
 */
final class CommitChunkJob implements ShouldQueue
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
        public readonly int $index,
        public readonly bool $first = false,
    ) {
        $this->tries = ImportsConfig::tries();
        $this->backoff = ImportsConfig::backoff();
        $this->timeout = ImportsConfig::timeout();
    }

    public function handle(ProcessChunkAction $action): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $action->execute($this->importBatchId, $this->index, $this->first, $this->batch());
    }
}
