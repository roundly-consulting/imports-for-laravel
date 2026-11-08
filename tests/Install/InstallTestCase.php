<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Install;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Imports\Tests\TestCase;

/**
 * `imports:install` publishes into the host's config/ and database/: here a throwaway directory
 * per test, set before the providers boot — never the shared Testbench skeleton. No imports
 * tables are migrated, as on a fresh host.
 */
abstract class InstallTestCase extends TestCase
{
    private string $sandbox = '';

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->sandbox = sys_get_temp_dir().'/imports-install-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox.'/config');
        File::ensureDirectoryExists($this->sandbox.'/database/migrations');

        $app->useConfigPath($this->sandbox.'/config');
        $app->useDatabasePath($this->sandbox.'/database');
    }

    protected function sandbox(): string
    {
        return $this->sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }
}
