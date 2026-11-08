<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('publishes the config and migrations into the sandbox, never the shared skeleton', function (): void {
    $exit = Artisan::call('imports:install');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($this->sandbox().'/config/imports.php')->toBeFile()
        ->and(glob($this->sandbox().'/database/migrations/*_create_import_sessions_table.php'))->toHaveCount(1)
        ->and(glob($this->sandbox().'/database/migrations/*.php'))->toHaveCount(4)
        ->and(config_path('imports.php'))->toStartWith($this->sandbox())
        ->and($output)->toContain('make:queue-batches-table')->toContain("Schedule::command('imports:sweep')");
});
