<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Config;
use Workbench\App\Http\TeamOwnerResolver;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchServiceProvider;
use Workbench\Database\Seeders\DatabaseSeeder;

test('the_workbench_serves_the_api_after_seeding', function (): void {
    $config = Config::loadFromYaml(dirname(__DIR__, 2));

    expect($config->getExtraAttributes()['providers'])->toContain(WorkbenchServiceProvider::class)
        ->and($config['seeders'])->toBe([DatabaseSeeder::class])
        ->and($config['env'])->toContain('IMPORTS_ROUTES=on', 'APP_ENV=local');

    (new WorkbenchServiceProvider($this->app))->register();
    config()->set('imports.routes.middleware', ['api', 'auth']);   // the routes were registered at boot
    $this->seed(DatabaseSeeder::class);

    expect(config('imports.routes.owner_resolver'))->toBe(TeamOwnerResolver::class)
        ->and(User::query()->count())->toBe(4);

    $this->actingAs(User::query()->where('email', 'admin@acme.test')->sole())
        ->getJson('/api/imports/importers')->assertOk()->assertJsonPath('data.0.key', 'assets');
    $this->actingAs(User::query()->where('email', 'member@acme.test')->sole())
        ->getJson('/api/imports/importers')->assertOk()->assertJsonPath('data', []);
});
