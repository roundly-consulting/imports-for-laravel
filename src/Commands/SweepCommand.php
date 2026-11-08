<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Imports\ImportsManager;

/**
 * @internal
 */
final class SweepCommand extends Command
{
    protected $signature = 'imports:sweep';

    protected $description = 'Expire stale uncommitted imports, fail stuck previews, commits and rollbacks, delete orphaned temp directories';

    public function handle(ImportsManager $imports): int
    {
        $report = $imports->sweep();

        $this->components->info(sprintf('Expired %d session(s), failed %d stuck preview(s), %d commit(s) and %d rollback(s), removed %d temp directory(ies).', $report->expired, $report->failedPreviews, $report->failedCommits, $report->failedRollbacks, $report->tempDirectories));

        return self::SUCCESS;
    }
}
