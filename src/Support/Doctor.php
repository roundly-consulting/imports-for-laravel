<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use Throwable;

/**
 * Every `imports:doctor` check, each with what to do about it.
 *
 * @internal
 */
final class Doctor
{
    /**
     * @return list<DoctorFinding>
     */
    public static function run(): array
    {
        $findings = [];

        try {
            $count = count(app(ImporterRegistry::class)->all());
            $findings[] = new DoctorFinding('importers', $count === 0 ? 'warning' : 'ok', $count === 0 ? 'No importers are registered (config imports.importers).' : sprintf('%d importer(s) valid.', $count));
        } catch (Throwable $exception) {
            $findings[] = new DoctorFinding('importers', 'error', $exception->getMessage());
        }

        $options = config('options.key_type');
        $optionsType = is_string($options) && $options !== '' ? KeyType::tryFrom($options) ?? KeyType::BigInt : KeyType::BigInt;
        $findings[] = $optionsType === ImportsConfig::keyType()
            ? new DoctorFinding('key_type', 'ok', 'imports.key_type matches options.key_type.')
            : new DoctorFinding('key_type', 'error', sprintf('imports.key_type (%s) differs from options.key_type (%s): saved mappings are owner-scoped options.', ImportsConfig::keyType()->value, $optionsType->value));

        $batches = config('queue.batching.table');
        $table = is_string($batches) && $batches !== '' ? $batches : 'job_batches';
        $findings[] = Schema::hasTable($table)
            ? new DoctorFinding('job_batches', 'ok', sprintf('The [%s] table exists.', $table))
            : new DoctorFinding('job_batches', 'error', sprintf('The [%s] table is missing: run php artisan make:queue-batches-table && php artisan migrate.', $table));

        $connection = ImportsConfig::queueConnection() ?? config('queue.default');
        $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;

        if (ImportsConfig::queueEnabled() && $driver === 'sync') {
            $findings[] = new DoctorFinding('queue', 'warning', 'The import queue is the sync driver: large previews and every commit run inside the request.');
        } else {
            $findings[] = new DoctorFinding('queue', 'ok', ImportsConfig::queueEnabled() ? sprintf('Imports run on the [%s] queue connection.', is_string($connection) ? $connection : 'default') : 'The queue is switched off: everything runs inline.');
        }

        $retryAfter = is_string($connection) ? config('queue.connections.'.$connection.'.retry_after') : null;

        if (is_numeric($retryAfter) && (int) $retryAfter <= ImportsConfig::timeout()) {
            $findings[] = new DoctorFinding('retry_after', 'error', sprintf('queue.connections.%s.retry_after (%d) must exceed imports.commit.timeout (%d), or long chunks are delivered twice.', (string) $connection, (int) $retryAfter, ImportsConfig::timeout()));
        } else {
            $findings[] = new DoctorFinding('retry_after', 'ok', 'retry_after exceeds the commit timeout.');
        }

        $disk = config('filesystems.disks.'.ImportsConfig::disk());

        if (! is_array($disk)) {
            $findings[] = new DoctorFinding('disk', 'error', sprintf('The disk [%s] is not configured (imports.storage.disk).', ImportsConfig::disk()));
        } elseif (($disk['visibility'] ?? null) === 'public') {
            $findings[] = new DoctorFinding('disk', 'error', sprintf('The disk [%s] is public; uploads hold personal data — use a private disk.', ImportsConfig::disk()));
        } else {
            $findings[] = new DoctorFinding('disk', 'ok', sprintf('Files are stored on the private [%s] disk.', ImportsConfig::disk()));
        }

        $findings[] = Durations::seconds(ImportsConfig::retention()) < Durations::seconds(ImportsConfig::undoWindow())
            ? new DoctorFinding('retention', 'warning', 'imports.storage.retention is shorter than imports.undo.window: a failed import cannot be resumed once its file is pruned.')
            : new DoctorFinding('retention', 'ok', 'File retention covers the undo window.');

        $missing = array_values(array_filter(['zip', 'xmlreader', 'xmlwriter', 'mbstring'], static fn (string $ext): bool => ! extension_loaded($ext)));
        $findings[] = $missing === []
            ? new DoctorFinding('extensions', 'ok', 'zip, xmlreader, xmlwriter and mbstring are loaded.')
            : new DoctorFinding('extensions', 'error', 'Missing PHP extensions: '.implode(', ', $missing).'.');

        $store = ImportsConfig::lockStore() ?? config('cache.default');
        $storeDriver = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;
        $findings[] = in_array($storeDriver, ['array', 'file', 'null'], true)
            ? new DoctorFinding('lock_store', 'warning', sprintf('The mapping lock store [%s] is not shared between servers; use redis or database (imports.mappings.lock_store).', $store))
            : new DoctorFinding('lock_store', 'ok', 'The mapping lock store is shared.');

        try {
            $scope = app(OwnerScope::class);
            $teams = class_exists('RoundlyConsulting\Teams\TeamsServiceProvider');
            $findings[] = $teams && ImportsConfig::ownerScope() === ExplicitOwnerScope::class
                ? new DoctorFinding('owner_scope', 'warning', 'teams-for-laravel is installed but imports use the default owner scope; see the TeamsOwnerScope recipe in the docs.')
                : new DoctorFinding('owner_scope', 'ok', sprintf('Owner scope: %s.', class_basename($scope)));
        } catch (Throwable $exception) {
            $findings[] = new DoctorFinding('owner_scope', 'error', $exception->getMessage());
        }

        $sessionModel = new (ImportModels::session());
        $findings[] = Schema::connection($sessionModel->getConnectionName())->hasTable($sessionModel->getTable())
            ? new DoctorFinding('migrations', 'ok', 'The imports tables exist.')
            : new DoctorFinding('migrations', 'error', 'The imports tables are missing: php artisan vendor:publish --tag=imports-migrations && php artisan migrate.');

        return $findings;
    }
}
