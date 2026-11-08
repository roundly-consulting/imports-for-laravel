<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use RoundlyConsulting\Imports\Commands\DoctorCommand;
use RoundlyConsulting\Imports\Commands\ImportersCommand;
use RoundlyConsulting\Imports\Commands\InstallCommand;
use RoundlyConsulting\Imports\Commands\PruneCommand;
use RoundlyConsulting\Imports\Commands\SweepCommand;
use RoundlyConsulting\Imports\Commands\TemplateCommand;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Contracts\ResolvesImportOwner;
use RoundlyConsulting\Imports\Http\AuthenticatedUserOwner;
use RoundlyConsulting\Imports\Options\SavedMappings;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Options\OptionsManager;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class ImportsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('imports')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasRoutes('imports.php', 'imports.routes.enabled', enabledByDefault: false)
            ->hasCommands([
                PruneCommand::class,
                SweepCommand::class,
                DoctorCommand::class,
                ImportersCommand::class,
                TemplateCommand::class,
                InstallCommand::class,
            ])
            ->contributesToAbout(static fn (): array => [
                'Importers' => (string) count(app(ImporterRegistry::class)->all()),
                'Queue' => ImportsConfig::queueEnabled()
                    ? sprintf('ON (connection %s, queue %s)', ImportsConfig::queueConnection() === null ? 'DEFAULT' : 'SET', ImportsConfig::queueName() === null ? 'DEFAULT' : 'SET')
                    : 'OFF (inline)',
                'Disk' => ImportsConfig::disk(),
                'Limits' => sprintf('%d KB, %d rows, %d columns', ImportsConfig::maxFileSizeKb(), ImportsConfig::maxRows(), ImportsConfig::maxColumns()),
                'Routes' => ImportsConfig::routesEnabled() ? 'ON' : 'OFF',
                'Key type' => ImportsConfig::keyType()->value,
                'Record key type' => ImportsConfig::recordKeyType()->value,
                'Primary key type' => ImportsConfig::primaryKeyType()->value,
                'Owner scope' => class_basename(ImportsConfig::ownerScope()),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ImportsManager::class);
        $this->app->singleton(ImporterRegistry::class);
        $this->app->bind(OwnerScope::class, static fn (Application $app): OwnerScope => self::resolved($app->make(ImportsConfig::ownerScope()), OwnerScope::class));
        $this->app->bind(ResolvesImportOwner::class, static fn (Application $app): ResolvesImportOwner => self::resolved($app->make(ImportsConfig::ownerResolver() ?? AuthenticatedUserOwner::class), ResolvesImportOwner::class));
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerBlueprintMacros();

        $this->callAfterResolving(OptionsManager::class, static function (OptionsManager $options): void {
            $options->register(['imports.mappings' => SavedMappings::class]);
        });

        RateLimiter::for('imports-upload', static fn (Request $request): Limit => ImportsConfig::uploadLimit() === 0 ? Limit::none() : Limit::perMinute(ImportsConfig::uploadLimit())->by(self::throttleKey($request)));
    }

    private static function throttleKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? 'user:'.$id : 'ip:'.$request->ip();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private static function resolved(mixed $instance, string $contract): object
    {
        return $instance instanceof $contract ? $instance : throw new LogicException(sprintf('The container did not resolve a %s.', class_basename($contract)));
    }
}
