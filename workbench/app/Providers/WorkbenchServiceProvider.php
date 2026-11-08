<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Http\TeamOwnerResolver;
use Workbench\App\Importers\AssetsImporter;
use Workbench\App\Importers\DocumentsImporter;
use Workbench\App\Importers\PeopleImporter;
use Workbench\App\Models\User;

/**
 * The click-through workbench (`vendor/bin/testbench serve`): the three importers, imports owned by
 * the signed-in user's team, and every migration the app needs. The test suite never loads it.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set([
            'auth.providers.users.model' => User::class,
            'imports.importers' => [AssetsImporter::class, PeopleImporter::class, DocumentsImporter::class],
            'imports.routes.owner_resolver' => TeamOwnerResolver::class,
            'imports.routes.middleware' => ['web', 'auth'],
            'filesystems.disks.documents' => ['driver' => 'local', 'root' => storage_path('app/private/documents')],
        ]);
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 3);

        // The package migrations are publish-only; the workbench runs them in place.
        $this->loadMigrationsFrom([
            $root.'/vendor/roundly-consulting/options-for-laravel/database/migrations',
            $root.'/database/migrations',
        ]);
    }
}
