<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Imports\Enums\SessionStatus;

it('publishes the config and migrations into the sandbox, never the shared skeleton', function (): void {
    $exit = Artisan::call('imports:install');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($this->sandbox().'/config/imports.php')->toBeFile()
        ->and(glob($this->sandbox().'/database/migrations/*_create_import_sessions_table.php'))->toHaveCount(1)
        ->and(glob($this->sandbox().'/database/migrations/*.php'))->toHaveCount(5)
        ->and(config_path('imports.php'))->toStartWith($this->sandbox())
        ->and($output)->toContain('make:queue-batches-table')->toContain("Schedule::command('imports:sweep')");
});

test('install_publishes_the_options_migration', function (): void {
    expect(Artisan::call('imports:install'))->toBe(0)
        ->and(glob($this->sandbox().'/database/migrations/*_create_options_table.php'))->toHaveCount(1)
        ->and(Artisan::output())->toContain('options-migrations');
});

test('install_skips_the_options_migration_when_the_table_exists', function (): void {
    Schema::create('options', static function (Blueprint $table): void {
        $table->id();
    });

    expect(Artisan::call('imports:install'))->toBe(0)
        ->and(glob($this->sandbox().'/database/migrations/*_create_options_table.php'))->toHaveCount(0)
        ->and(Artisan::output())->toContain('options table already exists');
});

test('the_readme_install_steps_produce_a_working_upload', function (): void {
    // Every `php artisan` line of the README's Installation block, in order (composer require is
    // the test app itself; Testbench's skeleton already ships job_batches).
    preg_match('/## Installation.*?```bash\n(.*?)```/s', (string) file_get_contents(dirname(__DIR__, 2).'/README.md'), $block);
    $commands = array_values(array_filter(array_map(
        static fn (string $line): string => trim((string) preg_replace('/\s+#.*$/', '', $line)),
        explode("\n", $block[1] ?? ''),
    ), static fn (string $line): bool => str_starts_with($line, 'php artisan ') && ! str_contains($line, 'make:queue-batches-table')));

    expect($commands)->not->toBeEmpty();

    foreach ($commands as $command) {
        expect(Artisan::call(substr($command, strlen('php artisan '))))->toBe(0);
    }

    // The host's own tables (teams, users, assets…).
    Artisan::call('migrate', ['--path' => dirname(__DIR__, 2).'/workbench/database/migrations', '--realpath' => true]);

    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)));

    expect($session->status())->toBe(SessionStatus::Mapped);
});
