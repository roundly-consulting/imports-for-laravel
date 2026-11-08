<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Actions\Preview\ValidateRowsAction;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Events\ImportPreviewed;
use RoundlyConsulting\Imports\Events\ImportPreviewProgressed;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Exceptions\UnknownImporterException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Jobs\ValidatePreviewJob;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;
use Workbench\Database\Factories\AssetFactory;
use Workbench\Database\Factories\InvitationFactory;
use Workbench\Database\Factories\UserFactory;
use Workbench\Database\Factories\VendorFactory;

test('an_unknown_reference_is_a_row_error', function (): void {
    $team = team();
    $rows = assetRows(1);
    $rows[0][7] = 'Nobody Ltd';

    $error = uploadAssets($team, admin($team), assetsCsv($rows))->preview()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0];

    expect($error->code)->toBe('unknown_reference')->and($error->field)->toBe('vendor')->and($error->message)->toContain('Nobody Ltd');
});

test('an_ambiguous_reference_is_a_row_error', function (): void {
    $team = team();
    VendorFactory::new()->count(2)->create(['team_id' => $team->id, 'name' => 'Lenovo']);
    $rows = assetRows(1);
    $rows[0][7] = 'Lenovo';

    $error = uploadAssets($team, admin($team), assetsCsv($rows))->preview()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0];

    expect($error->code)->toBe('ambiguous_reference');
});

test('references_resolve_by_the_first_unique_column_and_case_insensitively', function (): void {
    $team = team();
    $vendor = VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Lenovo', 'vat_id' => 'SK123']);
    UserFactory::new()->create(['team_id' => $team->id, 'email' => 'ada@example.com']);
    $rows = assetRows(2);
    $rows[0][7] = 'SK123';
    $rows[1][7] = 'Lenovo';
    $rows[0][8] = 'ADA@example.com';

    $batch = uploadAssets($team, admin($team), assetsCsv($rows))->preview()->commit();

    expect($batch->summary()->created)->toBe(2)
        ->and(Asset::query()->pluck('vendor_id')->unique()->all())->toBe([$vendor->id])
        ->and(Asset::query()->whereNotNull('assignee_id')->count())->toBe(1);
});

test('another_owners_record_is_never_a_reference', function (): void {
    $team = team();
    UserFactory::new()->create(['team_id' => team()->id, 'email' => 'other@example.com']);
    $rows = assetRows(1);
    $rows[0][8] = 'other@example.com';

    $error = uploadAssets($team, admin($team), assetsCsv($rows))->preview()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0];

    expect($error->code)->toBe('unknown_reference')->and($error->field)->toBe('assignee');
});

test('the_natural_key_falls_back_in_order', function (): void {
    $team = team();
    $existing = AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'OTHER', 'serial_number' => 'SN-00001']);
    $rows = assetRows(1);
    $rows[0][0] = '';   // no asset tag: the serial number matches

    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $match = ImportRowResult::query()->firstOrFail();

    expect($session->counts()->matched)->toBe(1)
        ->and($match->match_candidate)->toBe(1)
        ->and((string) $match->matched_record_id)->toBe((string) $existing->id)
        ->and($session->rows(RowFilter::duplicates())->paginate()->items()[0]->match?->id)->toBe($existing->id);
});

test('two_candidates_matching_different_records_conflict', function (): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'serial_number' => 'X']);
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'Y', 'serial_number' => 'SN-00001']);

    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();

    expect($session->counts()->invalid)->toBe(1)
        ->and($session->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->code)->toBe('conflicting_match');
});

test('more_than_one_record_for_a_key_is_ambiguous', function (): void {
    $team = team();
    AssetFactory::new()->count(2)->sequence(['serial_number' => 'A'], ['serial_number' => 'B'])->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001']);

    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();

    expect($session->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->code)->toBe('ambiguous_match');
});

test('a_record_matched_twice_makes_the_later_row_a_duplicate', function (): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'serial_number' => 'SN-00002']);
    $rows = assetRows(2);
    $rows[1][0] = '';   // row 3 matches the same record by its serial number

    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $duplicate = ImportRowResult::query()->where('status', RowStatus::DuplicateInFile)->firstOrFail();

    expect($session->counts()->matched)->toBe(1)
        ->and($session->counts()->duplicatesInFile)->toBe(1)
        ->and($duplicate->row_number)->toBe(3)
        ->and($duplicate->errors[0]['code'] ?? null)->toBe('same_record');
});

test('case_insensitive_natural_keys_match_across_case', function (): void {
    $team = team();
    InvitationFactory::new()->create(['team_id' => $team->id, 'email' => 'ada@example.com']);

    $session = Imports::for($team)->by(admin($team))
        ->upload('people', SpreadsheetFactory::csv(['Email'], [['ADA@Example.com'], ['ada@EXAMPLE.com']]))->preview();

    expect($session->counts()->matched)->toBe(1)->and($session->counts()->duplicatesInFile)->toBe(1);
});

test('an_invalid_row_does_not_make_a_later_row_a_duplicate', function (): void {
    $team = team();
    $rows = assetRows(2);
    $rows[1][0] = 'AST-0001';
    $rows[1][1] = '';
    $rows[0][2] = '';   // row 2 is invalid (no name) and shares the tag with row 3

    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();

    expect($session->counts()->invalid)->toBe(1)->and($session->counts()->valid)->toBe(1)->and($session->counts()->duplicates)->toBe(0);
});

test('the_template_hint_and_example_rows_are_skipped', function (string $locale): void {
    $team = team();
    $path = Imports::template('assets', TemplateFormat::Csv, $locale)->toTemporaryFile();
    $content = (string) file_get_contents($path)."AST-9,SN-9,Real row,active,2026-01-15,10.00,no,,\n";
    $content = $locale === 'sk' ? str_replace('AST-9,SN-9,Real row,active,2026-01-15,10.00,no,,', 'AST-9;SN-9;Real row;active;2026-01-15;10,00;nie;;', $content) : $content;

    $session = uploadAssets($team, admin($team), SpreadsheetFactory::file('template.csv', $content))->preview();

    expect($session->counts()->templateRows)->toBe(2)->and($session->counts()->valid)->toBe(1)->and($session->counts()->total)->toBe(1);
})->with(['en', 'sk']);

test('an_example_valued_row_beyond_the_template_rows_imports', function (): void {
    $team = team();
    $lines = explode("\r\n", trim((string) file_get_contents(Imports::template('assets', TemplateFormat::Csv, 'en')->toTemporaryFile())));
    // Header, two real rows (the template window), then a row that happens to equal the examples.
    $content = $lines[0]."\r\nAST-8,SN-8,Real row,active,2026-01-15,10.00,no,,\r\nAST-9,SN-9,Real row,active,2026-01-15,10.00,no,,\r\n".$lines[2]."\r\n";

    $session = uploadAssets($team, admin($team), SpreadsheetFactory::file('assets.csv', $content))->preview();

    expect($session->counts()->templateRows)->toBe(0)->and($session->counts()->valid)->toBe(3)
        ->and($session->rows(RowFilter::creates())->count())->toBe(3);

    $session->commit();

    expect(Asset::query()->count())->toBe(3);
});

test('a_blank_line_before_the_example_row_is_still_a_template_row', function (): void {
    $team = team();
    $lines = explode("\r\n", trim((string) file_get_contents(Imports::template('assets', TemplateFormat::Csv, 'en')->toTemporaryFile())));
    // The hint row deleted and a blank line left in its place.
    $content = $lines[0]."\r\n,,,,,,,,\r\n".$lines[2]."\r\nAST-9,SN-9,Real row,active,2026-01-15,10.00,no,,\r\n";

    $session = uploadAssets($team, admin($team), SpreadsheetFactory::file('assets.csv', $content))->preview();

    expect($session->counts()->templateRows)->toBe(1)->and($session->counts()->valid)->toBe(1);
});

test('five_hundred_rows_validate_in_the_request', function (): void {
    Bus::fake([ValidatePreviewJob::class]);
    $team = team();

    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(500)))->preview();

    Bus::assertNotDispatched(ValidatePreviewJob::class);
    expect($session->status())->toBe(SessionStatus::Previewed)->and($session->counts()->valid)->toBe(500);
});

test('five_hundred_one_rows_validate_on_the_queue_with_progress', function (): void {
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.preview.progress_every', 100);
    Event::fake([ImportPreviewProgressed::class, ImportPreviewed::class]);
    $team = team();

    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(501)))->preview();

    expect($session->status())->toBe(SessionStatus::Validating)->and($session->progress()->percent)->toBe(0);

    drainQueue();

    expect($session->status())->toBe(SessionStatus::Previewed)
        ->and($session->counts()->valid)->toBe(501)
        ->and($session->progress()->percent)->toBe(100);
    Event::assertDispatchedTimes(ImportPreviewProgressed::class, 5);
    Event::assertDispatched(ImportPreviewed::class);
});

test('a_mapping_change_during_async_validation_discards_the_stale_run', function (): void {
    config()->set('imports.preview.sync_threshold', 1);
    Bus::fake([ValidatePreviewJob::class]);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview();
    $stale = $session->model()->preview_version;

    $session->map([2 => 'name', 0 => 'asset_tag']);

    // The stale job runs now: it must not write anything.
    app(ValidateRowsAction::class)->execute($session->id(), $stale);

    expect($session->status())->toBe(SessionStatus::Mapped)->and($session->counts()->total)->toBe(0);
});

test('a_mapping_change_during_async_validation_still_runs_the_new_preview', function (): void {
    config()->set('imports.preview.sync_threshold', 1);
    Bus::fake([ValidatePreviewJob::class]);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview();
    $session->map([2 => 'name', 0 => 'asset_tag'])->preview();

    $jobs = Bus::dispatched(ValidatePreviewJob::class);
    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->uniqueId())->not->toBe($jobs[1]->uniqueId());

    $jobs[1]->handle(app(ValidateRowsAction::class));

    expect($session->status())->toBe(SessionStatus::Previewed)->and($session->counts()->valid)->toBe(3);
});

test('changing_the_mapping_invalidates_the_preview', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview();
    $version = $session->model()->preview_version;

    $session->map(['Asset tag' => 'asset_tag', 'Name' => 'name']);

    expect($session->status())->toBe(SessionStatus::Mapped)
        ->and($session->model()->preview_version)->toBeGreaterThan($version)
        ->and($session->counts()->total)->toBe(0);
});

test('changing_the_mode_reclassifies_without_rereading', function (): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001']);
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview();

    expect($session->counts()->skips)->toBe(1)->and($session->counts()->creates)->toBe(2);

    // The file is gone: a re-read would fail, the re-classification does not need it.
    Storage::disk('imports')->delete($session->model()->file_path);

    $session->mode(DuplicateMode::Update);
    expect($session->counts()->updates)->toBe(1)->and($session->counts()->creates)->toBe(2)->and($session->status())->toBe(SessionStatus::Previewed)
        ->and(ImportRowResult::query()->firstOrFail()->outcome)->toBe(RowOutcome::Update);

    $session->mode(DuplicateMode::CreateNew);
    expect($session->counts()->creates)->toBe(3)->and($session->counts()->updates)->toBe(0);
});

test('a_mode_the_importer_does_not_offer_is_refused', function (): void {
    $team = team();
    $session = Imports::for($team)->by(admin($team))->uploadFiles('documents', SpreadsheetFactory::file('a.txt', 'x'));

    expect(fn () => uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->mode(DuplicateMode::Update))->not->toThrow(Throwable::class)
        ->and(fn () => $session->mode(DuplicateMode::Update))->toThrow(UnknownImporterException::class);
});

test('the_preview_states_that_updates_are_not_reverted', function (): void {
    $team = team();
    AssetFactory::new()->count(2)->sequence(['asset_tag' => 'AST-0001'], ['asset_tag' => 'AST-0002'])->create(['team_id' => $team->id, 'name' => 'Old']);

    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview();
    expect($session->previewData()->updateNotice)->toBeNull();

    $session->mode(DuplicateMode::Update);
    $preview = $session->previewData();
    $updates = $session->rows(RowFilter::updates())->paginate()->items();

    expect($preview->updateNotice)->toBe('2 existing records will be updated. Undo removes only records this import creates; updates are not reverted.')
        ->and($preview->mode)->toBe(DuplicateMode::Update)
        ->and($updates)->toHaveCount(2)
        ->and(collect($updates[0]->changes)->firstWhere('field', 'name')?->from)->toBe('Old')
        ->and(collect($updates[0]->changes)->firstWhere('field', 'name')?->to)->toBe('Laptop 1');
});

test('a_clean_file_stores_no_row_results', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(50)))->preview();

    expect(ImportRowResult::query()->count())->toBe(0)
        ->and($session->rows(RowFilter::creates())->count())->toBe(50)
        ->and($session->rows(RowFilter::creates())->paginate(20, 3)->items())->toHaveCount(10)
        ->and(iterator_to_array($session->rows(RowFilter::creates())->cursor(), false))->toHaveCount(50);
});

test('row_results_hold_no_row_payloads', function (): void {
    $team = team();
    $rows = assetRows(2);
    $rows[0][2] = '';
    $rows[0][3] = 'secret-status-value';

    uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $stored = json_encode(ImportRowResult::query()->get()->toArray());

    expect($stored)->not->toContain('Laptop 2')->not->toContain('SN-00001')->toContain('secret-status-value');
});

test('mode_changes_are_refused_while_validating_and_after_commit', function (): void {
    config()->set('imports.preview.sync_threshold', 1);
    Bus::fake([ValidatePreviewJob::class]);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview();

    expect(fn () => $session->mode(DuplicateMode::Update))->toThrow(ImportStateException::class);
});
