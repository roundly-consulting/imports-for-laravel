<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\DataTransferObjects\RowResult;
use RoundlyConsulting\Imports\Exceptions\ImportNotFoundException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\KeyTypes\UlidTeam;
use RoundlyConsulting\Imports\Tests\KeyTypes\UlidVendor;
use RoundlyConsulting\Imports\Tests\KeyTypes\UuidItem;

test('ulid_and_uuid_owners_and_records_work', function (): void {
    $team = UlidTeam::query()->create(['name' => 'Ulid team']);
    $file = SpreadsheetFactory::csv(['code', 'name'], [['A', 'One'], ['B', 'Two']]);

    $session = Imports::for($team)->upload('items', $file)->preview();
    $batch = $session->commit();

    expect(Str::isUlid((string) $session->id()))->toBeTrue()
        ->and(Str::isUlid((string) $batch->id()))->toBeTrue()
        ->and($batch->summary()->created)->toBe(2)
        ->and(Str::isUuid((string) ImportedRecord::query()->value('record_id')))->toBeTrue()
        ->and(ImportSession::query()->value('owner_id'))->toBe($team->id);

    // Re-import matches by code; saved mappings live on the ulid owner too.
    $again = Imports::for($team)->upload('items', $file)->preview();
    Imports::for($team)->mappings('items')->save('Default', ['code' => 'code', 'name' => 'name']);

    expect($again->counts()->matched)->toBe(2)
        ->and(Imports::for($team)->mappings('items')->all())->toHaveCount(1);

    $batch->rollback();
    expect(UuidItem::query()->count())->toBe(0);
});

it('O-8: ulid package keys look up sessions and batches, and a malformed id is not found', function (): void {
    $team = UlidTeam::query()->create(['name' => 'Ulid team']);
    $session = Imports::for($team)->upload('items', SpreadsheetFactory::csv(['code', 'name'], [['A', 'One']]))->preview();
    $batch = $session->commit();

    expect(Imports::for($team)->session((string) $session->id())->id())->toBe($session->id())
        ->and(Imports::for($team)->batch((string) $batch->id())->id())->toBe($batch->id())
        ->and(fn () => Imports::for($team)->session('123'))->toThrow(ImportNotFoundException::class)
        ->and(fn () => Imports::batch('not-a-ulid'))->toThrow(ImportNotFoundException::class);
});

test('a_natural_key_with_a_ulid_reference_matches_and_labels_every_row', function (): void {
    $team = UlidTeam::query()->create(['name' => 'Ulid team']);
    $acme = UlidVendor::query()->create(['team_id' => $team->id, 'name' => 'Acme']);
    $globex = UlidVendor::query()->create(['team_id' => $team->id, 'name' => 'Globex']);
    $existing = UuidItem::query()->create(['team_id' => $team->id, 'code' => 'A', 'name' => 'Old', 'vendor_id' => $acme->id]);
    $file = SpreadsheetFactory::csv(['code', 'name', 'vendor'], [['a', 'One', 'Acme'], ['A', 'Two', 'Globex'], ['B', 'Three', 'Nobody']]);

    $session = Imports::for($team)->upload('vendor-items', $file)->preview();
    $rows = iterator_to_array($session->rows(RowFilter::all())->cursor(), false);

    expect(Str::isUlid((string) $globex->id))->toBeTrue()
        ->and(array_map(static fn (RowResult $row): array => [$row->status->value, $row->label], $rows))->toBe([
            ['matched', 'a · Acme'],
            ['valid', 'A · Globex'],
            ['invalid', 'B · Nobody'],
        ])
        ->and($rows[0]->match?->id)->toBe($existing->id);

    $session->commit();

    expect(UuidItem::query()->count())->toBe(2)
        ->and(UuidItem::query()->where('vendor_id', $globex->id)->value('code'))->toBe('A');
});
