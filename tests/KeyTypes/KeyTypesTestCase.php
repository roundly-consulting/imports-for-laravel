<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\KeyTypes;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Imports\ImportsServiceProvider;
use RoundlyConsulting\Imports\Tests\TestCase;
use RoundlyConsulting\Options\OptionsServiceProvider;

/**
 * ULID owners, UUID records and ULID package ids: every morph and key column follows its config.
 */
abstract class KeyTypesTestCase extends TestCase
{
    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [OptionsServiceProvider::class, ImportsServiceProvider::class, dirname(__DIR__, 2).'/workbench/database/migrations', __DIR__.'/migrations'];
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'imports.key_type' => 'ulid',
            'imports.record_key_type' => 'uuid',
            'imports.primary_key_type' => 'ulid',
            'options.key_type' => 'ulid',
            'imports.importers' => [UuidItemsImporter::class],
        ];
    }
}
