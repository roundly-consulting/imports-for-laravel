<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Teams\TeamAsset;
use RoundlyConsulting\Imports\Tests\Teams\TeamAssetsImporter;
use RoundlyConsulting\Imports\Tests\Teams\TeamsGroupTestCase;
use RoundlyConsulting\Teams\Database\Factories\TeamFactory;
use RoundlyConsulting\Teams\Facades\Teams;
use RoundlyConsulting\Teams\Models\Team;

/**
 * B-9, C-64 and C-65 against teams-for-laravel's real ScopedToTeam, with the TeamsOwnerScope
 * recipe and `teams.scoping.without_context = throw`.
 */
function realTeam(): Team
{
    return TeamFactory::new()->create();
}

function countAssets(Team $team): int
{
    return Teams::runAs($team, static fn (): int => TeamAsset::query()->count());
}

beforeEach(function (): void {
    TeamAssetsImporter::$writeTeam = null;
});

test('an_import_never_writes_outside_its_owner_context_with_the_teams_scope (real teams)', function (): void {
    $mine = realTeam();
    $theirs = realTeam();
    $file = SpreadsheetFactory::csv(['asset_tag', 'name'], [['T-1', 'One'], ['T-2', 'Two']]);

    // The request runs as the other team; the import still writes only into its owner.
    $batch = Teams::runAs($theirs, static fn () => Imports::for($mine)->upload('team-assets', $file)->preview()->commit());

    TeamAssetsImporter::$writeTeam = $theirs->getKey();
    $refused = Imports::for($mine)->upload('team-assets', SpreadsheetFactory::csv(['asset_tag', 'name'], [['T-3', 'Three']]))->preview()->commit();

    expect($batch->summary()->created)->toBe(2)
        ->and($refused->summary()->failed)->toBe(1)
        ->and(countAssets($mine))->toBe(2)
        ->and(countAssets($theirs))->toBe(0);
})->group('teams')->skip(fn (): bool => ! TeamsGroupTestCase::teamsInstalled(), 'teams-for-laravel is not installed (CI job test-teams adds it)');

test('the_pipeline_never_queries_host_models_outside_the_owner_context (real teams)', function (): void {
    $team = realTeam();
    $file = SpreadsheetFactory::csv(['asset_tag', 'name'], [['T-1', 'One']]);

    expect(fn () => TeamAsset::query()->count())->toThrow(RuntimeException::class, 'No team context is set.');

    $batch = Imports::for($team)->upload('team-assets', $file)->preview()->commit();
    $again = Imports::for($team)->upload('team-assets', $file)->preview();
    $batch->rollback();

    expect($again->counts()->matched)->toBe(1)->and(countAssets($team))->toBe(0);
})->group('teams')->skip(fn (): bool => ! TeamsGroupTestCase::teamsInstalled(), 'teams-for-laravel is not installed (CI job test-teams adds it)');

test('a_commit_job_runs_as_the_import_owner_not_the_dispatchers_team (real teams)', function (): void {
    config()->set('imports.queue.connection', 'database');
    $owner = realTeam();
    $dispatcher = realTeam();

    Teams::runAs($dispatcher, static function () use ($owner): void {
        Imports::for($owner)->upload('team-assets', SpreadsheetFactory::csv(['asset_tag', 'name'], [['T-1', 'One']]))->preview()->commit();
    });
    drainQueue();

    expect(countAssets($owner))->toBe(1)->and(countAssets($dispatcher))->toBe(0);
})->group('teams')->skip(fn (): bool => ! TeamsGroupTestCase::teamsInstalled(), 'teams-for-laravel is not installed (CI job test-teams adds it)');
