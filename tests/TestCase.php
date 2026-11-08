<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Imports\ImportsServiceProvider;
use RoundlyConsulting\Options\OptionsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use Workbench\App\Importers\AssetsImporter;
use Workbench\App\Importers\DocumentsImporter;
use Workbench\App\Importers\PeopleImporter;
use Workbench\App\Models\User;

/**
 * The test app: the package and its hard dependencies, and the workbench app (teams, users,
 * vendors, assets, invitations, documents) with its three importers.
 */
abstract class TestCase extends PackageTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [CryptoServiceProvider::class, OptionsServiceProvider::class, ImportsServiceProvider::class];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [OptionsServiceProvider::class, ImportsServiceProvider::class, dirname(__DIR__).'/workbench/database/migrations'];
    }

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        $token = (string) (getenv('TEST_TOKEN') ?: '0');
        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'roundly-imports-tests'.DIRECTORY_SEPARATOR.$token;

        return [
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'queue.default' => 'sync',
            'queue.batching.database' => 'testing',
            'queue.failed.database' => 'testing',
            'auth.providers.users.model' => User::class,
            'filesystems.disks.imports' => ['driver' => 'local', 'root' => $root.DIRECTORY_SEPARATOR.'disk'],
            'filesystems.disks.documents' => ['driver' => 'local', 'root' => $root.DIRECTORY_SEPARATOR.'documents'],
            'imports.storage.disk' => 'imports',
            'imports.storage.tmp_path' => $root.DIRECTORY_SEPARATOR.'tmp',
            'imports.importers' => [AssetsImporter::class, PeopleImporter::class, DocumentsImporter::class],
            'imports.commit.backoff' => [0],
        ];
    }
}
