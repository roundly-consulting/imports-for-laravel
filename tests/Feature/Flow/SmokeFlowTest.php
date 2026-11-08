<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;

it('runs upload, preview, commit and rollback', function (): void {
    $team = team();
    $user = admin($team);

    $session = uploadAssets($team, $user, assetsCsv(assetRows(5)));
    expect($session->status())->toBe(SessionStatus::Mapped);

    $session->preview();
    expect($session->status())->toBe(SessionStatus::Previewed)
        ->and($session->counts()->valid)->toBe(5);

    $batch = $session->commit();
    expect($batch->status())->toBe(BatchStatus::Completed)
        ->and(Asset::query()->count())->toBe(5)
        ->and($batch->summary()->created)->toBe(5);

    $batch->rollback();
    expect(Asset::query()->count())->toBe(0)
        ->and($batch->rollbackReport()?->deleted)->toBe(5);
});

it('runs the flow of the technical docs, call for call', function (): void {
    // Mirrors "The flow" in docs/packages/imports-for-laravel/technical/README.md: keep both identical.
    $team = team();
    $user = admin($team);
    $file = SpreadsheetFactory::csv(['Inventory no', 'Name'], [['AST-1', 'Laptop'], ['', ''], ['AST-3', '']]);

    $session = Imports::for($team)->by($user)->upload('assets', $file);   // checked + stored
    $session->suggestMapping();                       // MappingSuggestion (applied already when complete)
    $session->map(['Inventory no' => 'asset_tag', 'Name' => 'name'])   // optional: by header text or column number
        ->saveMapping('ERP export');              // optional: reused for identical headers
    $session->mode(DuplicateMode::Update)->preview(); // ≤ 500 rows: now; more: queued (poll isPreviewed())
    $session->counts();                               // ImportCounts
    $session->rows(RowFilter::invalid())->paginate(50);
    $batch = $session->commit();                      // a Laravel job batch
    $batch->progress();
    $batch->summary();
    $batch->rollback();                               // within imports.undo.window

    expect($session->counts()->valid)->toBe(1)
        ->and($session->rows(RowFilter::invalid())->paginate(50)->total())->toBe(1)
        ->and($batch->isRolledBack())->toBeTrue()
        ->and(Asset::query()->count())->toBe(0);
});
