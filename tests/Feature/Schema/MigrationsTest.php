<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\Imports\ImportsServiceProvider;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;

it('publishes its migrations instead of auto-loading them', function (): void {
    expect(ImportsServiceProvider::class)
        ->toNotAutoLoadMigrations()
        ->toPublishMigrationsTimestamped('imports-migrations', 4);
});

/**
 * Four FK edges: batches → sessions, row results → sessions, imported records → batches and
 * → sessions. Table names are configurable, so each expression maps to its default.
 */
it('runs its migrations in an order every foreign key can follow', function (): void {
    expect(__DIR__.'/../../../database/migrations')->toHaveRunnableMigrationOrder(foreignKeys: 4, tableResolvers: [
        "ImportsConfig::table('sessions')" => 'import_sessions',
        "ImportsConfig::table('batches')" => 'import_batches',
        "ImportsConfig::table('row_results')" => 'import_row_results',
        "ImportsConfig::table('records')" => 'imported_records',
    ]);
});

it('applies on postgres and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../../database/migrations')
        ->toApplyOnConnection('pgsql', 4)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('pgsql'), 'no PostgreSQL connection');

it('applies on mysql and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../../database/migrations')
        ->toApplyOnConnection('mysql', 4)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('mysql'), 'no MySQL connection');

it('declares every model swappable through its config key', function (): void {
    expect(ImportSession::class)->toBeSwappableVia('imports.models.session')
        ->and(ImportBatch::class)->toBeSwappableVia('imports.models.batch')
        ->and(ImportRowResult::class)->toBeSwappableVia('imports.models.row_result')
        ->and(ImportedRecord::class)->toBeSwappableVia('imports.models.record');
});

it('soft-deletes only sessions: batches, row results and the ledger are append-only', function (): void {
    expect(in_array(SoftDeletes::class, class_uses_recursive(ImportSession::class), true))->toBeTrue()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(ImportBatch::class), true))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(ImportRowResult::class), true))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(ImportedRecord::class), true))->toBeFalse();
});

it('refuses a table name too long or not a name', function (string $value): void {
    config()->set('imports.tables.sessions', $value);

    ImportsConfig::table('sessions');
})->with([
    'too long' => [str_repeat('x', 31)],
    'not a name' => ['Import Sessions'],
])->throws(InvalidImportsConfigurationException::class, 'must be a table name');

it('refuses an unknown table handle', function (): void {
    ImportsConfig::table('nope');
})->throws(InvalidImportsConfigurationException::class);
