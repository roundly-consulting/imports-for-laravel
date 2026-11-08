<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Definition\FileDuplicates;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\ContextOwnerScope;
use RoundlyConsulting\Imports\Tests\Support\ContextTeams;
use RoundlyConsulting\Imports\Tests\Support\TeamScopedAsset;
use RoundlyConsulting\Imports\Tests\Support\TeamUpdatableAssetsImporter;
use Workbench\App\Importers\DocumentsImporter;

// Dual review 2026-11-08, O-5: the "updates" page of a preview and a file batch's duplicate
// callback read host models outside `OwnerScope::run()` — "no team context" under the teams recipe.

beforeEach(function (): void {
    app()->bind(OwnerScope::class, ContextOwnerScope::class);
    ContextTeams::set(null);
});

afterEach(function (): void {
    DocumentsImporter::$duplicates = null;
});

it('O-5: the updates page of a preview reads host models inside the owner context', function (): void {
    Imports::register(TeamUpdatableAssetsImporter::class);
    $team = team();
    $user = admin($team);
    Imports::for($team)->by($user)->upload('team-updatable-assets', SpreadsheetFactory::csv(['Asset tag', 'Name'], [['T-1', 'One']]))->preview()->commit();

    $again = Imports::for($team)->by($user)->upload('team-updatable-assets', SpreadsheetFactory::csv(['Asset tag', 'Name'], [['T-1', 'Renamed']]));
    $again->mode(DuplicateMode::Update)->preview();
    $items = $again->rows(RowFilter::updates())->paginate()->items();

    expect($again->counts()->updates)->toBe(1)
        ->and($items)->toHaveCount(1)
        ->and($items[0]->changes)->not->toBe([]);
});

it('O-5: a file batch duplicate callback runs inside the owner context', function (): void {
    $team = team();
    DocumentsImporter::$duplicates = FileDuplicates::using(static fn (string $hash): ?TeamScopedAsset => TeamScopedAsset::query()->where('asset_tag', $hash)->first());

    $session = Imports::for($team)->by(admin($team))->uploadFiles('documents', SpreadsheetFactory::file('a.pdf', 'content'))->preview();

    expect($session->counts()->valid)->toBe(1);
});
