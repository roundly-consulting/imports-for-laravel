<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\DataTransferObjects\RowResult;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\VendorKeyedAssetsImporter;
use Workbench\Database\Factories\AssetFactory;
use Workbench\Database\Factories\VendorFactory;

/**
 * @param  iterable<RowResult>  $rows
 * @return list<array{int, string, string|null, string|null}>
 */
function summarize(iterable $rows): array
{
    $summary = [];

    foreach ($rows as $row) {
        $summary[] = [$row->rowNumber, $row->status->value, $row->outcome?->value, $row->label];
    }

    return $summary;
}

/**
 * Three creates, one update and one error (AST-0005 has no name).
 */
function mixedPreview(): SessionHandle
{
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0004', 'serial_number' => 'X-1', 'name' => 'Old']);
    $rows = assetRows(5);
    $rows[4][2] = '';

    return uploadAssets($team, admin($team), assetsCsv($rows))->preview()->mode(DuplicateMode::Update);
}

test('a_preview_of_three_creates_one_update_and_one_error_lists_five_labelled_rows', function (): void {
    $session = mixedPreview();
    $page = $session->rows(RowFilter::all())->paginate();

    expect($page->total())->toBe(5)
        ->and(summarize($page->items()))->toBe([
            [2, 'valid', 'create', 'AST-0001'],
            [3, 'valid', 'create', 'AST-0002'],
            [4, 'valid', 'create', 'AST-0003'],
            [5, 'matched', 'update', 'AST-0004'],
            [6, 'invalid', null, 'AST-0005'],
        ])
        ->and($session->rows()->count())->toBe(5)
        ->and(summarize($session->rows()->cursor()))->toBe(summarize($page->items()))
        ->and(summarize($session->rows()->paginate(2, 3)->items()))->toBe([[6, 'invalid', null, 'AST-0005']])
        // Nothing new is stored: plain creates are still read from the file.
        ->and(ImportRowResult::query()->count())->toBe(2);
});

test('flagged_keeps_the_old_all_and_every_filter_carries_labels', function (): void {
    $session = mixedPreview();

    expect(summarize($session->rows(RowFilter::flagged())->paginate()->items()))->toBe([
        [5, 'matched', 'update', 'AST-0004'],
        [6, 'invalid', null, 'AST-0005'],
    ])
        ->and($session->rows(RowFilter::flagged())->count())->toBe(2)
        ->and(summarize($session->rows(RowFilter::flagged())->cursor()))->toHaveCount(2)
        ->and(summarize($session->rows(RowFilter::invalid())->paginate()->items()))->toBe([[6, 'invalid', null, 'AST-0005']])
        ->and($session->rows(RowFilter::updates())->paginate()->items()[0]->label)->toBe('AST-0004')
        ->and(array_column(summarize($session->rows(RowFilter::creates())->paginate()->items()), 3))->toBe(['AST-0001', 'AST-0002', 'AST-0003'])
        ->and(summarize($session->rows(RowFilter::field('name'))->paginate()->items()))->toBe([[6, 'invalid', null, 'AST-0005']])
        ->and(summarize($session->rows(RowFilter::field('name'))->cursor()))->toBe([[6, 'invalid', null, 'AST-0005']])
        ->and($session->rows(RowFilter::field('name'))->count())->toBe(1);
});

test('status_valid_lists_the_plain_creates_from_the_file', function (): void {
    $session = mixedPreview();
    $valid = RowFilter::status(RowStatus::Valid);

    expect(array_column(summarize($session->rows($valid)->paginate(2)->items()), 3))->toBe(['AST-0001', 'AST-0002'])
        ->and($session->rows($valid)->paginate(2)->total())->toBe(3)
        ->and($session->rows($valid)->count())->toBe(3)
        ->and($session->rows(RowFilter::status(RowStatus::Valid, RowStatus::Invalid))->count())->toBe(4);
});

test('a_row_without_a_label_or_key_value_is_labelled_row_n_in_the_session_locale', function (): void {
    $team = team();
    $rows = assetRows(2);
    $rows[0][0] = '';   // no asset tag …
    $rows[0][1] = '';   // … and no serial number: a create without a key
    $rows[1][0] = '';
    $rows[1][1] = '';
    $rows[1][2] = '';   // the same, and no name: an error whose key cannot be read

    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $slovak = Imports::for($team)->by(admin($team))->upload('assets', assetsCsv($rows), locale: 'sk')->preview();

    expect(summarize($session->rows()->cursor()))->toBe([[2, 'valid', 'create', 'Row 2'], [3, 'invalid', null, 'Row 3']])
        ->and(array_column(summarize($slovak->rows()->cursor()), 3))->toBe(['Riadok 2', 'Riadok 3']);
});

test('a_declared_label_field_wins_over_the_natural_key_and_falls_back_to_it_when_blank', function (): void {
    Imports::register(VendorKeyedAssetsImporter::class);
    VendorKeyedAssetsImporter::reset();
    VendorKeyedAssetsImporter::$labelField = 'serial_number';
    $team = team();
    VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Acme']);

    $session = Imports::for($team)->by(admin($team))->upload('vendor-assets', SpreadsheetFactory::csv(['Name', 'Vendor', 'Serial number'], [
        ['Laptop', 'Acme', 'SN-1'],
        ['Phone', 'Acme', '  '],
        ['Tablet', 'Nobody', 'SN-3'],
    ]))->preview();

    expect(array_column(summarize($session->rows()->cursor()), 3))->toBe(['SN-1', 'Phone · Acme', 'SN-3']);
    VendorKeyedAssetsImporter::reset();
});

test('deep_pages_start_at_their_commit_chunk_and_match_the_stream', function (string $format): void {
    config()->set('imports.commit.chunk_size', 7);
    $team = team();
    // The template's unchanged example row first: it is not a data row.
    $rows = [['AST-0042', 'SN-99812', 'ThinkPad T14', 'active', '2026-01-15', '1299.00', 'yes', '', ''], ...assetRows(40)];
    $rows[20][2] = '';
    $file = $format === 'xlsx' ? SpreadsheetFactory::xlsx(assetHeaders(), $rows) : assetsCsv($rows);

    $session = uploadAssets($team, admin($team), $file)->preview();
    $all = summarize($session->rows()->cursor());
    $paged = [];

    for ($page = 1; $page <= 14; $page++) {
        $paged = [...$paged, ...summarize($session->rows()->paginate(3, $page)->items())];
    }

    expect($session->counts()->templateRows)->toBe(1)
        ->and($all)->toHaveCount(40)
        ->and($all[0])->toBe([3, 'valid', 'create', 'AST-0001'])
        ->and($all[19])->toBe([22, 'invalid', null, 'AST-0020'])
        ->and($paged)->toBe($all)
        ->and($session->rows()->paginate(3, 15)->items())->toBe([])
        ->and($session->rows()->paginate(50, 9)->items())->toBe([]);
})->with(['csv', 'xlsx']);

test('without_a_preview_or_once_the_file_is_pruned_all_lists_the_stored_rows', function (): void {
    $team = team();
    $rows = assetRows(3);
    $rows[1][2] = '';
    $uploaded = uploadAssets($team, admin($team), assetsCsv($rows));

    expect($uploaded->rows()->paginate()->total())->toBe(0)
        ->and(iterator_to_array($uploaded->rows()->cursor()))->toBe([])
        ->and(fn () => $uploaded->rows(RowFilter::creates())->count())->not->toThrow(Throwable::class)
        ->and(fn () => iterator_to_array($uploaded->rows(RowFilter::creates())->cursor()))->toThrow(ImportStateException::class);

    $session = $uploaded->preview();
    $session->model()->forceFill(['file_pruned_at' => now()])->save();

    expect(summarize($session->rows()->paginate()->items()))->toBe([[3, 'invalid', null, 'Row 3']])
        ->and($session->rows()->count())->toBe(1);
});

test('a_file_gone_missing_leaves_stored_rows_labelled_row_n', function (): void {
    $session = mixedPreview();
    Storage::disk('imports')->delete($session->model()->file_path);

    expect(summarize($session->rows(RowFilter::flagged())->paginate()->items()))->toBe([
        [5, 'matched', 'update', 'Row 5'],
        [6, 'invalid', null, 'Row 6'],
    ]);
});

test('a_file_batch_lists_every_entry_with_its_file_name', function (): void {
    $team = team();
    $session = Imports::for($team)->by(admin($team))->uploadFiles('documents', [SpreadsheetFactory::file('a.txt', 'x'), SpreadsheetFactory::file('b.exe', 'y')]);

    expect(array_column(summarize($session->rows()->cursor()), 3))->toBe(['a.txt', 'b.exe'])
        ->and(array_column(summarize($session->rows(RowFilter::flagged())->cursor()), 3))->toBe(['b.exe']);
});

test('a_file_longer_than_a_read_block_lists_and_labels_every_row', function (): void {
    $team = team();
    $rows = assetRows(400);

    foreach ($rows as $index => $row) {
        if ($index % 2 === 1) {
            $rows[$index][2] = '';  // every second row has no name
        }
    }

    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $all = summarize($session->rows()->cursor());
    $flagged = summarize($session->rows(RowFilter::flagged())->cursor());

    expect($all)->toHaveCount(400)
        ->and($all[199])->toBe([201, 'invalid', null, 'AST-0200'])
        ->and($all[200])->toBe([202, 'valid', 'create', 'AST-0201'])
        ->and($all[399])->toBe([401, 'invalid', null, 'AST-0400'])
        ->and($flagged)->toHaveCount(200)
        ->and($flagged[199])->toBe([401, 'invalid', null, 'AST-0400'])
        ->and(summarize($session->rows()->paginate(150, 2)->items()))->toBe(array_slice($all, 150, 150));
});
