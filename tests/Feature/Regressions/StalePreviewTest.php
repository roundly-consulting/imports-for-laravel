<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Actions\Preview\ValidateRowsAction;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportPreviewProgressed;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Jobs\ValidatePreviewJob;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;

// Dual review 2026-11-08, O-2 = F-1: a validation run whose version went stale kept inserting row
// results (and wrote/deleted the one shared working copy) after a newer preview superseded it.
// The race is made deterministic: at the 250-row progress tick of the old run, the user remaps and
// previews again inline — what a second queue worker does in production.

/**
 * @param  Closure(): void  $remap
 */
function remapAtFirstTick(Closure $remap): void
{
    $fired = false;

    Event::listen(ImportPreviewProgressed::class, static function () use (&$fired, $remap): void {
        if (! $fired) {
            $fired = true;
            $remap();
        }
    });
}

it('O-2: a superseded validation run writes no row results into the newer preview', function (): void {
    $team = team();
    $user = admin($team);
    $rows = assetRows(300);

    for ($i = 251; $i < 300; $i++) {
        $rows[$i][5] = 'not-a-price';   // invalid only while Price is mapped
    }

    $import = uploadAssets($team, $user, assetsCsv($rows));
    $id = $import->id();
    remapAtFirstTick(static fn () => Imports::session($id)->by($user)->map([0 => 'asset_tag', 1 => 'serial_number', 2 => 'name'])->preview());

    $import->preview();

    expect($import->status())->toBe(SessionStatus::Previewed)
        ->and($import->counts()->invalid)->toBe(0)
        ->and(ImportRowResult::query()->where('session_id', $id)->where('status', RowStatus::Invalid->value)->count())->toBe(0);

    $batch = $import->commit();

    expect(Asset::query()->count())->toBe(300)->and($batch->summary()->invalid)->toBe(0);
});

it('O-2: a superseded xlsx run never deletes the newer preview\'s working copy', function (): void {
    $team = team();
    $user = admin($team);
    $import = uploadAssets($team, $user, SpreadsheetFactory::xlsx(assetHeaders(), assetRows(300)));
    $id = $import->id();
    remapAtFirstTick(static fn () => Imports::session($id)->by($user)->map([0 => 'asset_tag', 1 => 'serial_number', 2 => 'name'])->preview());

    $import->preview();
    $session = $import->model()->refresh();

    expect($session->status)->toBe(SessionStatus::Previewed)
        ->and($session->working_copy_path)->not->toBeNull()
        ->and(Storage::disk('imports')->exists((string) $session->working_copy_path))->toBeTrue()
        ->and(Storage::disk('imports')->files(dirname((string) $session->working_copy_path)))->toHaveCount(2);   // source + this version's copy

    expect($import->commit()->summary()->created)->toBe(300);
});

it('O-2: a stale run cannot write between a reset and the next preview', function (): void {
    config()->set('imports.preview.sync_threshold', 1);
    Bus::fake([ValidatePreviewJob::class]);
    $team = team();
    $rows = assetRows(3);
    $rows[1][5] = 'not-a-price';   // a stored (invalid) row result, if the stale run gets to write
    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $stale = $session->model()->preview_version;
    $fired = false;

    // The remap's delete of the old row results is the moment a still-running run of the old
    // version could slip its window in: run it right there, deterministically.
    DB::listen(static function (QueryExecuted $query) use (&$fired, $session, $stale): void {
        if (! $fired && str_contains($query->sql, 'import_row_results') && str_starts_with(strtolower(ltrim($query->sql)), 'delete')) {
            $fired = true;
            app(ValidateRowsAction::class)->execute($session->id(), $stale);
        }
    });

    $session->map([0 => 'asset_tag', 1 => 'serial_number', 2 => 'name', 5 => 'price']);

    expect($fired)->toBeTrue()
        ->and($session->status())->toBe(SessionStatus::Mapped)
        ->and(ImportRowResult::query()->where('session_id', $session->id())->count())->toBe(0);
});
