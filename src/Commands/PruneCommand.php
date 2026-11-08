<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Imports\ImportsManager;

/**
 * @internal
 */
final class PruneCommand extends Command
{
    protected $signature = 'imports:prune';

    protected $description = 'Delete import files, working copies and row results past retention (and old history when configured)';

    public function handle(ImportsManager $imports): int
    {
        $report = $imports->prune();

        $this->components->info(sprintf('Pruned %d file(s), %d row result(s), %d session(s), %d batch(es), %d ledger row(s).', $report->files, $report->rowResults, $report->sessions, $report->batches, $report->records));

        return self::SUCCESS;
    }
}
