<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RowStatus;
use Workbench\App\Models\Asset;
use Workbench\Database\Factories\AssetFactory;

// Dual review 2026-11-08, O-11: natural keys matched live records only, while the host's unique
// index still covers soft-deleted ones — the preview planned a create, the commit failed the row
// with "created meanwhile" although nothing was created.

it('O-11: a row whose key belongs to a soft-deleted record is flagged in the preview', function (DuplicateMode $mode): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'serial_number' => 'SN-00001'])->delete();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->mode($mode)->preview();
    $invalid = $session->rows(RowFilter::invalid())->paginate()->items();

    expect($session->counts()->creates)->toBe(1)
        ->and($session->counts()->invalid)->toBe(1)
        ->and($invalid)->toHaveCount(1)
        ->and($invalid[0]->rowNumber)->toBe(2)
        ->and($invalid[0]->errors[0]->code)->toBe('matches_deleted_record')
        ->and($invalid[0]->errors[0]->message)->toContain('AST-0001');

    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(1)
        ->and($batch->summary()->failed)->toBe(0)
        ->and($batch->summary()->invalid)->toBe(1)
        ->and(Asset::withTrashed()->count())->toBe(2)
        ->and($session->rows(RowFilter::status(RowStatus::Failed))->count())->toBe(0);
})->with([DuplicateMode::Skip, DuplicateMode::Update, DuplicateMode::CreateNew]);

it('O-11: a live record wins over a soft-deleted one with the same key', function (): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'serial_number' => 'SN-OLD'])->delete();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'serial_number' => 'SN-00001']);
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();

    expect($session->counts()->matched)->toBe(1)->and($session->counts()->invalid)->toBe(0);
});
