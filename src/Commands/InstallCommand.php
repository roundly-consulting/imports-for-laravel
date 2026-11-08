<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\OptionsTable;

/**
 * @internal
 */
final class InstallCommand extends Command
{
    protected $signature = 'imports:install';

    protected $description = 'Publish the imports config, its migrations and the options migration, and print the remaining setup steps';

    public function handle(): int
    {
        foreach (['sessions', 'batches', 'row_results', 'records'] as $table) {
            if (Schema::hasTable(ImportsConfig::table($table))) {
                $this->components->error(sprintf('The table [%s] already exists: the migrations were published before.', ImportsConfig::table($table)));

                return self::FAILURE;
            }
        }

        $this->call('vendor:publish', ['--tag' => 'imports-config']);
        $this->call('vendor:publish', ['--tag' => 'imports-migrations']);

        // Saved column mappings live in options-for-laravel, whose migration is publish-only.
        if (OptionsTable::exists()) {
            $this->components->info(sprintf('The %s table already exists: options-migrations not published.', OptionsTable::name()));
        } else {
            $this->call('vendor:publish', ['--tag' => 'options-migrations']);
            $this->components->info('Published options-migrations: saved column mappings live in options-for-laravel.');
        }

        $this->components->info('Next steps:');
        $this->line('  php artisan make:queue-batches-table   # Laravel\'s job_batches table, if you do not have it');
        $this->line('  php artisan migrate');
        $this->line('  // routes/console.php');
        $this->line("  Schedule::command('imports:sweep')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();");
        $this->line("  Schedule::command('imports:prune')->daily()->onOneServer();");

        return self::SUCCESS;
    }
}
