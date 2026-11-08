<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Teams;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Imports\ImportsServiceProvider;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;
use RoundlyConsulting\Imports\Tests\TestCase;
use RoundlyConsulting\Options\OptionsServiceProvider;

/**
 * Against the real teams-for-laravel (CI job `test-teams` adds it ad hoc; it is never a
 * committed dependency). Teams' providers and migrations join only when it is installed —
 * without it every test in the group skips visibly.
 */
abstract class TeamsGroupTestCase extends TestCase
{
    /** @var list<string> */
    private const array TEAMS_PROVIDERS = [
        'RoundlyConsulting\Contacts\ContactsServiceProvider',
        'RoundlyConsulting\Addresses\AddressesServiceProvider',
        'RoundlyConsulting\Connections\ConnectionsServiceProvider',
        'RoundlyConsulting\Approvals\ApprovalsServiceProvider',
        'RoundlyConsulting\Teams\TeamsServiceProvider',
    ];

    public static function teamsInstalled(): bool
    {
        return class_exists('RoundlyConsulting\Teams\TeamsServiceProvider');
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [...parent::packageProviders(), ...(self::teamsInstalled() ? self::TEAMS_PROVIDERS : [])];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return self::teamsInstalled()
            ? [OptionsServiceProvider::class, ...array_slice(self::TEAMS_PROVIDERS, 0, 5), ImportsServiceProvider::class, __DIR__.'/migrations']
            : parent::migrationSources();
    }

    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'teams.scoping.without_context' => 'throw',
            'imports.owner_scope' => self::teamsInstalled() ? TeamsOwnerScope::class : ExplicitOwnerScope::class,
            'imports.importers' => self::teamsInstalled() ? [TeamAssetsImporter::class] : [],
        ];
    }
}
