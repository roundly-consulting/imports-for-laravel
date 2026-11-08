<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\KeyTypes\UlidTeam;
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
