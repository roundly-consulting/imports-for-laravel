<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\DataTransferObjects\RowResult;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\VendorKeyedAssetsImporter;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Team;
use Workbench\Database\Factories\AssetFactory;
use Workbench\Database\Factories\VendorFactory;

beforeEach(function (): void {
    VendorKeyedAssetsImporter::reset();
    Imports::register(VendorKeyedAssetsImporter::class);
});

/**
 * @param  list<list<string>>  $rows  name, vendor, serial number
 */
function uploadVendorAssets(Team $team, array $rows): SessionHandle
{
    return Imports::for($team)->by(admin($team))->upload('vendor-assets', SpreadsheetFactory::csv(['Name', 'Vendor', 'Serial number'], $rows));
}

/**
 * @return list<array{int, string, string|null, string|null}>
 */
function rowSummary(SessionHandle $session, ?RowFilter $filter = null): array
{
    return array_map(
        static fn (RowResult $row): array => [$row->rowNumber, $row->status->value, $row->outcome?->value, $row->label],
        iterator_to_array($session->rows($filter)->cursor(), false),
    );
}

test('a_natural_key_with_a_reference_matches_the_existing_record_and_does_not_duplicate_it', function (): void {
    $team = team();
    $acme = VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Acme', 'vat_id' => 'SK100']);
    VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Globex', 'vat_id' => 'SK200']);
    $existing = AssetFactory::new()->create(['team_id' => $team->id, 'name' => 'Laptop', 'vendor_id' => $acme->id, 'serial_number' => 'OLD']);

    $session = uploadVendorAssets($team, [
        ['Laptop', 'Acme', 'NEW'],      // same name + vendor: the existing record
        ['Laptop', 'Globex', 'G-1'],    // same name, another vendor: a new record
    ])->preview();

    expect($session->counts()->matched)->toBe(1)
        ->and($session->counts()->creates)->toBe(1)
        ->and($session->rows(RowFilter::duplicates())->paginate()->items()[0]->match?->id)->toBe($existing->id);

    $session->mode(DuplicateMode::Update);
    $batch = $session->commit();

    expect($batch->summary()->updated)->toBe(1)
        ->and($batch->summary()->created)->toBe(1)
        ->and(Asset::query()->count())->toBe(2)
        ->and($existing->refresh()->serial_number)->toBe('NEW');

    // The same file again: both rows now match, nothing new is created.
    $again = uploadVendorAssets($team, [['Laptop', 'Acme', 'NEW'], ['Laptop', 'Globex', 'G-1']])->preview();
    $again->commit();

    expect($again->counts()->matched)->toBe(2)->and(Asset::query()->count())->toBe(2);
});

test('the_reference_part_matches_whichever_lookup_column_the_row_used', function (): void {
    $team = team();
    $acme = VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Acme', 'vat_id' => 'SK100']);
    AssetFactory::new()->create(['team_id' => $team->id, 'name' => 'Laptop', 'vendor_id' => $acme->id]);

    $session = uploadVendorAssets($team, [
        ['Laptop', 'SK100', ''],    // Acme by its VAT id: the existing record
        ['Phone', 'Acme', ''],
        ['Phone', 'SK100', ''],     // the same key as the row above, by another column
    ])->preview();

    expect(rowSummary($session))->toBe([
        [2, 'matched', 'skip', 'Laptop · SK100'],
        [3, 'valid', 'create', 'Phone · Acme'],
        [4, 'duplicate_in_file', 'skip', 'Phone · SK100'],
    ]);
});

test('an_unresolvable_reference_in_the_key_is_a_row_error_and_never_matches', function (): void {
    $team = team();
    $other = team();
    $foreign = VendorFactory::new()->create(['team_id' => $other->id, 'name' => 'Initech']);
    AssetFactory::new()->create(['team_id' => $other->id, 'name' => 'Laptop', 'vendor_id' => $foreign->id]);

    $session = uploadVendorAssets($team, [
        ['Laptop', 'Initech', ''],  // another team's vendor: not found inside this team
        ['Laptop', 'Nobody', ''],
    ])->preview();
    $invalid = $session->rows(RowFilter::invalid())->paginate()->items();

    expect($session->counts()->invalid)->toBe(2)
        ->and($session->counts()->matched)->toBe(0)
        ->and(array_map(static fn (RowResult $row): string => $row->errors[0]->code, $invalid))->toBe(['unknown_reference', 'unknown_reference'])
        ->and(array_map(static fn (RowResult $row): ?string => $row->label, $invalid))->toBe(['Laptop · Initech', 'Laptop · Nobody']);
});

test('a_blank_reference_leaves_its_candidate_out_like_any_blank_key_part', function (): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'name' => 'Laptop', 'vendor_id' => null, 'serial_number' => 'SN-1']);
    VendorKeyedAssetsImporter::$naturalKey = NaturalKey::by('name', 'vendor')->then('serial_number');

    $session = uploadVendorAssets($team, [['Laptop', '', 'SN-1'], ['Laptop', '', '']])->preview();

    expect(rowSummary($session))->toBe([
        [2, 'matched', 'skip', 'SN-1'],     // the serial number matched: its candidate labels the row
        [3, 'valid', 'create', 'Row 3'],    // no key part filled: "Row N"
    ]);
});

test('the_storeAs_column_names_the_reference_too_and_case_folding_skips_the_key_column', function (): void {
    $team = team();
    $acme = VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Acme']);
    AssetFactory::new()->create(['team_id' => $team->id, 'name' => 'Laptop', 'vendor_id' => $acme->id]);
    // Folding compares LOWER(column): on the integer vendor_id it would fail on PostgreSQL.
    VendorKeyedAssetsImporter::$naturalKey = NaturalKey::by('name', 'vendor_id')->caseInsensitive();

    $session = uploadVendorAssets($team, [['LAPTOP', 'Acme', ''], ['laptop', 'Acme', '']])->preview();

    expect($session->counts()->matched)->toBe(1)
        ->and($session->counts()->duplicatesInFile)->toBe(1)
        ->and($session->rows(RowFilter::status(RowStatus::Matched))->paginate()->items()[0]->outcome)->toBe(RowOutcome::Skip);
});
