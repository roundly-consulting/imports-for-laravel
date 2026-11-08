<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Document;

// Dual review 2026-11-08, O-6: undo marks every ledger row `rolled_back_at`, kept records included,
// so a file kept by undo stopped counting as imported and could be imported a second time.

it('O-6: a file kept by undo still counts as imported', function (): void {
    $team = team();
    $user = admin($team);
    $batch = Imports::for($team)->by($user)->uploadFiles('documents', SpreadsheetFactory::file('a.pdf', 'content'))->preview()->commit();
    Document::query()->update(['name' => 'renamed by a user']);
    $batch->rollback();

    expect($batch->rollbackReport()?->keptChanged)->toBe(1);

    $again = Imports::for($team)->by($user)->uploadFiles('documents', SpreadsheetFactory::file('a.pdf', 'content'))->preview();
    expect($again->counts()->matched)->toBe(1)->and($again->counts()->valid)->toBe(0);

    $again->commit();
    expect(Document::query()->count())->toBe(1);
});

it('O-6: a record kept by undo still reports its import', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    Asset::query()->update(['name' => 'Edited after the import']);
    $batch->rollback();

    $asset = Asset::query()->firstOrFail();
    expect($batch->rollbackReport()?->keptChanged)->toBe(1)
        ->and($asset->wasImported())->toBeTrue()
        ->and($asset->importBatch()?->getKey())->toBe($batch->model()->getKey());
});

it('O-6: a file undo removed can be imported again', function (): void {
    $team = team();
    $user = admin($team);
    $batch = Imports::for($team)->by($user)->uploadFiles('documents', SpreadsheetFactory::file('a.pdf', 'content'))->preview()->commit();
    $batch->rollback();

    expect($batch->rollbackReport()?->deleted)->toBe(1);

    $again = Imports::for($team)->by($user)->uploadFiles('documents', SpreadsheetFactory::file('a.pdf', 'content'))->preview();
    expect($again->counts()->valid)->toBe(1)->and($again->counts()->matched)->toBe(0);
});
