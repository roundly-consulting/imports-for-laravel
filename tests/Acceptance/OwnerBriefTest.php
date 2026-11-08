<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Actions\Commit\ProcessChunkAction;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Jobs\CommitChunkJob;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\Checksums;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\ContextOwnerScope;
use RoundlyConsulting\Imports\Tests\Support\ContextTeams;
use RoundlyConsulting\Imports\Tests\Support\TeamAssetsImporter;
use RoundlyConsulting\Imports\Tests\Support\ZipCrafter;
use Workbench\App\Hooks;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Invitation;
use Workbench\Database\Factories\AssetFactory;
use Workbench\Database\Factories\VendorFactory;

/**
 * The ten tests the owner brief requires, by name (plan §12, B-1 … B-10).
 */

/**
 * 50 rows: two invalid (a blank required name, an impossible date) and one duplicate.
 *
 * @return list<list<string>>
 */
function fiftyRows(string $duplicateTag): array
{
    $rows = assetRows(50);
    $rows[9][2] = '';                 // row 11: name is required
    $rows[19][4] = '31.02.2026';      // row 21: no such date
    $rows[49][0] = $duplicateTag;     // row 51: the duplicate
    $rows[49][1] = '';

    return $rows;
}

test('a_fifty_row_file_with_two_invalid_rows_and_one_duplicate_previews_exactly_that_and_saves_nothing (duplicate in the file)', function (): void {
    $team = team();
    $events = 0;
    foreach (['saving', 'creating', 'updating', 'deleting'] as $event) {
        Event::listen('eloquent.'.$event.': '.Asset::class, function () use (&$events): void {
            $events++;
        });
    }

    $session = uploadAssets($team, admin($team), assetsCsv(fiftyRows('AST-0001')))->preview();
    $counts = $session->counts();

    expect([$counts->valid, $counts->invalid, $counts->duplicates, $counts->total])->toBe([47, 2, 1, 50])
        ->and($counts->duplicatesInFile)->toBe(1)
        ->and(Asset::query()->count())->toBe(0)
        ->and(Hooks::$calls)->toBe([])
        ->and($events)->toBe(0)
        ->and($session->rows(RowFilter::invalid())->paginate()->total())->toBe(2);
});

test('a_fifty_row_file_with_two_invalid_rows_and_one_duplicate_previews_exactly_that_and_saves_nothing (duplicate of an existing record)', function (): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-9999', 'serial_number' => 'SN-X']);
    $before = Checksums::of(Asset::query()->firstOrFail()->getRawOriginal());

    $session = uploadAssets($team, admin($team), assetsCsv(fiftyRows('AST-9999')))->preview();
    $counts = $session->counts();

    expect([$counts->valid, $counts->invalid, $counts->duplicates])->toBe([47, 2, 1])
        ->and($counts->matched)->toBe(1)
        ->and(Asset::query()->count())->toBe(1)
        ->and(Checksums::of(Asset::query()->firstOrFail()->getRawOriginal()))->toBe($before)
        ->and(Hooks::$calls)->toBe([]);
});

test('reimporting_the_same_file_with_skip_creates_nothing_new', function (): void {
    $team = team();
    $user = admin($team);
    $file = assetsCsv(assetRows(10));

    uploadAssets($team, $user, $file)->preview()->commit();
    expect(Asset::query()->count())->toBe(10);

    $again = uploadAssets($team, $user, $file)->mode(DuplicateMode::Skip)->preview();
    expect($again->counts()->creates)->toBe(0)->and($again->counts()->matched)->toBe(10);

    $batch = $again->commit();

    expect(Asset::query()->count())->toBe(10)
        ->and($batch->summary()->created)->toBe(0)
        ->and($batch->summary()->skipped)->toBe(10);
});

test('update_mode_changes_only_matched_rows', function (): void {
    $team = team();
    $user = admin($team);
    $matched = AssetFactory::new()->count(3)->sequence(
        ['asset_tag' => 'AST-0001', 'name' => 'Old 1'],
        ['asset_tag' => 'AST-0002', 'name' => 'Old 2'],
        ['asset_tag' => 'AST-0003', 'name' => 'Old 3'],
    )->create(['team_id' => $team->id]);
    $untouched = AssetFactory::new()->count(2)->create(['team_id' => $team->id]);
    $other = Checksums::of(Asset::query()->whereKey($untouched->modelKeys())->orderBy('id')->get()->map->getRawOriginal()->all());

    $rows = assetRows(5);   // AST-0001 … AST-0005: three matches, two new
    $batch = uploadAssets($team, $user, assetsCsv($rows))->mode(DuplicateMode::Update)->preview()->commit();

    expect($batch->summary()->updated)->toBe(3)
        ->and($batch->summary()->created)->toBe(2)
        ->and(Asset::query()->whereKey($matched->modelKeys())->orderBy('id')->pluck('name')->all())->toBe(['Laptop 1', 'Laptop 2', 'Laptop 3'])
        ->and(Checksums::of(Asset::query()->whereKey($untouched->modelKeys())->orderBy('id')->get()->map->getRawOriginal()->all()))->toBe($other)
        ->and(Asset::query()->count())->toBe(7);
});

test('rollback_removes_unchanged_created_rows_keeps_changed_ones_and_calls_the_rollback_hook', function (): void {
    $team = team();
    $user = admin($team);

    $assets = uploadAssets($team, $user, assetsCsv(assetRows(3)))->preview()->commit();
    Asset::query()->where('asset_tag', 'AST-0002')->firstOrFail()->update(['name' => 'Renamed after import']);

    $people = Imports::for($team)->by($user)->upload('people', SpreadsheetFactory::csv(['Email', 'Role'], [['a@example.com', 'member'], ['b@example.com', 'admin']]))->preview()->commit();
    Hooks::reset();

    $assets->rollback();
    $people->rollback();

    expect(Asset::query()->pluck('asset_tag')->all())->toBe(['AST-0002'])
        ->and($assets->rollbackReport()?->deleted)->toBe(2)
        ->and($assets->rollbackReport()?->keptChanged)->toBe(1)
        ->and(Hooks::$calls)->toBe(['rollback:0', 'rollback:0'])
        ->and(Invitation::query()->pluck('status')->all())->toBe(['revoked', 'revoked'])
        ->and($people->rollbackReport()?->handled)->toBe(2);
});

test('a_windows_1250_csv_is_rejected_with_a_clear_message', function (): void {
    $team = team();

    try {
        uploadAssets($team, admin($team), SpreadsheetFactory::file('export.csv', (string) file_get_contents(__DIR__.'/../Fixtures/windows-1250.csv')));
        $this->fail('Expected a rejection.');
    } catch (ImportFileRejectedException $exception) {
        expect($exception->reason)->toBe(RejectionReason::NotUtf8)
            ->and($exception->getMessage())->toContain('Windows-1250')->toContain('CSV UTF-8')
            ->and($exception->context['line'])->toBe(1);
    }
});

test('a_semicolon_csv_is_accepted', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3), ';'))->preview();

    expect($session->model()->delimiter)->toBe(';')->and($session->counts()->valid)->toBe(3);
});

test('a_utf8_bom_csv_is_accepted', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(assetHeaders(), assetRows(2), bom: true))->preview();

    expect($session->model()->encoding)->toBe('utf-8-bom')
        ->and($session->headers()[0])->toBe('Asset tag')
        ->and($session->counts()->valid)->toBe(2);
});

test('an_xlsx_sheet_can_be_chosen_by_name', function (): void {
    $team = team();
    $file = SpreadsheetFactory::xlsx(['Note'], [['ignore me']], sheet: 'Readme', extraSheets: ['Assets' => [assetHeaders(), ...assetRows(2)]]);

    $session = Imports::for($team)->by(admin($team))->upload('assets', $file, sheet: 'Assets')->preview();

    expect($session->model()->sheet)->toBe('Assets')->and($session->counts()->valid)->toBe(2);
});

test('an_xlsx_sheet_can_be_chosen_by_index', function (): void {
    $team = team();
    $file = SpreadsheetFactory::xlsx(['Note'], [['ignore me']], sheet: 'Readme', extraSheets: ['Assets' => [assetHeaders(), ...assetRows(3)]]);

    $session = Imports::for($team)->by(admin($team))->upload('assets', $file, sheet: 2)->preview();

    expect($session->model()->sheet)->toBe('Assets')->and($session->counts()->valid)->toBe(3);
});

test('the_first_visible_sheet_is_the_default', function (): void {
    $team = team();
    $file = SpreadsheetFactory::xlsx(assetHeaders(), assetRows(4), sheet: 'Assets', extraSheets: ['Other' => [['Note']]]);

    $session = uploadAssets($team, admin($team), $file);

    expect($session->model()->sheet)->toBe('Assets')
        ->and(array_map(static fn ($s): string => $s->name, $session->sheets()))->toBe(['Assets', 'Other'])
        ->and($session->model()->data_rows)->toBe(4);
});

test('the_row_limit_is_enforced', function (string $format): void {
    config()->set('imports.limits.rows', 5);
    $team = team();
    $file = $format === 'csv' ? assetsCsv(assetRows(6)) : SpreadsheetFactory::xlsx(assetHeaders(), assetRows(6));

    expect(fn () => uploadAssets($team, admin($team), $file))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBe(RejectionReason::TooManyRows));

    expect(uploadAssets($team, admin($team), $format === 'csv' ? assetsCsv(assetRows(5)) : SpreadsheetFactory::xlsx(assetHeaders(), assetRows(5)))->model()->data_rows)->toBe(5);
})->with(['csv', 'xlsx']);

test('the_file_size_limit_is_enforced', function (string $format): void {
    config()->set('imports.limits.file_size', 1);   // 1 KB
    $team = team();
    $file = $format === 'csv' ? assetsCsv(assetRows(40)) : SpreadsheetFactory::xlsx(assetHeaders(), assetRows(400));

    expect(fn () => uploadAssets($team, admin($team), $file))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBe(RejectionReason::TooLarge));
})->with(['csv', 'xlsx']);

test('the_column_limit_is_enforced', function (string $format): void {
    config()->set('imports.limits.columns', 5);
    $team = team();
    $file = $format === 'csv' ? assetsCsv(assetRows(2)) : SpreadsheetFactory::xlsx(assetHeaders(), assetRows(2));

    expect(fn () => uploadAssets($team, admin($team), $file))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBe(RejectionReason::TooManyColumns));
})->with(['csv', 'xlsx']);

test('a_retried_chunk_does_not_double_create', function (): void {
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.commit.chunk_size', 5);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(12)))->preview();

    // (a) A chunk-level fault: a lost connection after row 7's savepoint, first attempt only. The
    // whole chunk rolls back and the retry redoes it.
    $faults = 1;
    Hooks::$onCreate = static function (int $row) use (&$faults): void {
        if ($row === 8 && $faults > 0) {
            $faults--;

            throw new QueryException('testing', 'insert into assets', [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
        }
    };

    $batch = $session->commit();
    // A worker stops on a lost connection (its supervisor starts a new one): run it like that.
    for ($restarts = 0; $restarts < 3; $restarts++) {
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);
    }

    expect($batch->status())->toBe(BatchStatus::Completed)
        ->and(Asset::query()->count())->toBe(12)
        ->and(Asset::query()->distinct()->count('asset_tag'))->toBe(12)
        ->and(ImportedRecord::query()->count())->toBe(12)
        ->and(ImportedRecord::query()->distinct()->count('row_number'))->toBe(12)
        ->and($faults)->toBe(0);

    // (b) The worker died after the chunk committed but before the ack: the redelivered job
    // finds its chunk claimed and does nothing.
    (new CommitChunkJob($batch->id(), 0))->handle(app(ProcessChunkAction::class));
    app(ProcessChunkAction::class)->execute($batch->id(), 1);

    expect(Asset::query()->count())->toBe(12)->and(ImportedRecord::query()->count())->toBe(12);
});

test('an_import_never_writes_outside_its_owner_context', function (): void {
    $mine = team();
    $theirs = team();
    AssetFactory::new()->create(['team_id' => $theirs->id, 'asset_tag' => 'AST-0001', 'name' => 'Theirs']);

    $batch = uploadAssets($mine, admin($mine), assetsCsv(assetRows(3)))->mode(DuplicateMode::Update)->preview()->commit();

    expect($batch->summary()->created)->toBe(3)->and($batch->summary()->updated)->toBe(0)
        ->and(Asset::query()->where('team_id', $theirs->id)->pluck('name')->all())->toBe(['Theirs'])
        ->and(Asset::query()->where('team_id', $mine->id)->count())->toBe(3);
});

test('an_import_never_writes_outside_its_owner_context_with_the_teams_scope', function (): void {
    app()->bind(OwnerScope::class, ContextOwnerScope::class);
    Imports::register(TeamAssetsImporter::class);
    $mine = team();
    $theirs = team();
    $user = admin($mine);

    // The request runs in another team's context; every step still runs as the import owner.
    ContextTeams::set($theirs);
    $batch = Imports::for($mine)->by($user)->upload('team-assets', SpreadsheetFactory::csv(['Asset tag', 'Name'], [['T-1', 'One'], ['T-2', 'Two']]))->preview()->commit();

    // A callback that tries to write for the other team is refused by the write guard.
    TeamAssetsImporter::$writeTeam = $theirs->id;
    $refused = Imports::for($mine)->by($user)->upload('team-assets', SpreadsheetFactory::csv(['Asset tag', 'Name'], [['T-3', 'Three']]))->preview()->commit();
    TeamAssetsImporter::$writeTeam = null;
    ContextTeams::set(null);

    expect($batch->summary()->created)->toBe(2)
        ->and($refused->summary()->failed)->toBe(1)
        ->and(Asset::query()->where('team_id', $mine->id)->count())->toBe(2)
        ->and(Asset::query()->where('team_id', $theirs->id)->count())->toBe(0);
});

test('an_import_never_writes_outside_its_owner_context_references', function (): void {
    $mine = team();
    $theirs = team();
    VendorFactory::new()->create(['team_id' => $theirs->id, 'name' => 'Lenovo', 'vat_id' => 'SK1']);

    $rows = assetRows(1);
    $rows[0][7] = 'Lenovo';
    $session = uploadAssets($mine, admin($mine), assetsCsv($rows))->preview();

    expect($session->counts()->invalid)->toBe(1)
        ->and($session->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->code)->toBe('unknown_reference');
});

test('an_import_never_writes_outside_its_owner_context_matches', function (): void {
    $mine = team();
    $theirs = team();
    AssetFactory::new()->create(['team_id' => $theirs->id, 'asset_tag' => 'AST-0001']);

    $session = uploadAssets($mine, admin($mine), assetsCsv(assetRows(1)))->preview();

    expect($session->counts()->matched)->toBe(0)->and($session->counts()->valid)->toBe(1);
});

test('an_import_never_writes_outside_its_owner_context_rollback', function (): void {
    $mine = team();
    $batch = uploadAssets($mine, admin($mine), assetsCsv(assetRows(2)))->preview()->commit();

    // Move one created record to another team after the import: undo must not reach it.
    $theirs = team();
    Asset::query()->where('asset_tag', 'AST-0002')->update(['team_id' => $theirs->id]);

    $batch->rollback();

    expect(Asset::query()->where('team_id', $theirs->id)->count())->toBe(1)
        ->and($batch->rollbackReport()?->missing)->toBe(1)
        ->and($batch->rollbackReport()?->deleted)->toBe(1);
});

test('a_zip_bomb_is_rejected', function (): void {
    $team = team();
    $bomb = ZipCrafter::bomb(5 * 1024 * 1024);

    expect(fn () => Imports::for($team)->by(admin($team))->uploadFiles('documents', SpreadsheetFactory::file('bomb.zip', (string) file_get_contents($bomb))))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBeIn([RejectionReason::ZipRatio, RejectionReason::ZipEntryTooLarge]));

    expect(ImportSession::query()->withTrashed()->count())->toBe(0);
});

test('a_path_traversal_archive_is_rejected', function (): void {
    $team = team();
    $zip = ZipCrafter::make(['../../etc/evil.txt' => 'x', 'ok.txt' => 'fine']);

    expect(fn () => Imports::for($team)->by(admin($team))->uploadFiles('documents', SpreadsheetFactory::file('evil.zip', (string) file_get_contents($zip))))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBe(RejectionReason::ZipUnsafePath));
});
