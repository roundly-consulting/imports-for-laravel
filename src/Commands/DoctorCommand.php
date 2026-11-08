<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Imports\Support\Doctor;

/**
 * @internal
 */
final class DoctorCommand extends Command
{
    protected $signature = 'imports:doctor';

    protected $description = 'Check the imports setup: importers, key types, job batches, queue, disk, retention, extensions, locks, the options table';

    public function handle(): int
    {
        $errors = 0;

        foreach (Doctor::run() as $finding) {
            match ($finding->level) {
                'ok', 'info' => $this->components->info($finding->check.': '.$finding->message),
                'warning' => $this->components->warn($finding->check.': '.$finding->message),
                default => $this->components->error($finding->check.': '.$finding->message),
            };

            $errors += $finding->level === 'error' ? 1 : 0;
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
