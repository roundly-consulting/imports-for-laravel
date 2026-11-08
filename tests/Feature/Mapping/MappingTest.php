<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\DataTransferObjects\ColumnMapping;
use RoundlyConsulting\Imports\DataTransferObjects\MappedColumn;
use RoundlyConsulting\Imports\Enums\MappingSource;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\MappingSaved;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Exceptions\InvalidMappingException;
use RoundlyConsulting\Imports\Exceptions\InvalidSavedMappingException;
use RoundlyConsulting\Imports\Exceptions\TooManySavedMappingsException;
use RoundlyConsulting\Imports\Exceptions\UnknownImporterException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Mapping\HeaderNormalizer;
use RoundlyConsulting\Imports\Options\SavedMappings;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Options\Exceptions\UnauthorizedOption;
use RoundlyConsulting\Options\OptionsManager;

function invalidMappingErrors(Closure $map): array
{
    try {
        $map();
    } catch (InvalidMappingException $exception) {
        return $exception->errors;
    }

    throw new RuntimeException('Expected an invalid mapping.');
}

test('headers_normalize_to_comparable_keys', function (): void {
    expect(HeaderNormalizer::normalize('Inventárne č. *', 'sk'))->toBe('inventarne_c')
        ->and(HeaderNormalizer::normalize('  E-mail (required) '))->toBe('e_mail')
        ->and(HeaderNormalizer::normalize('Dátum (povinné)', 'sk'))->toBe('datum')
        ->and(HeaderNormalizer::normalize(null))->toBe('');
});

test('slovak_headers_map_to_fields', function (): void {
    $team = team();
    $headers = ['Inventárne číslo', 'Sériové číslo', 'Názov *', 'Stav', 'Dátum nákupu', 'Cena', 'Poistený', 'Dodávateľ', 'Používateľ'];
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv($headers, assetRows(1)));
    $suggestion = $session->suggestMapping();

    expect($session->status())->toBe(SessionStatus::Mapped)
        ->and(array_map(static fn ($c): ?string => $c->field, $suggestion->columns))->toBe(['asset_tag', 'serial_number', 'name', 'status', 'purchased_on', 'price', 'insured', 'vendor', 'assignee'])
        ->and($suggestion->columns[0]->source)->toBe(MappingSource::Label)
        ->and($suggestion->columns[0]->confidence)->toBe(0.98)
        ->and($session->model()->mapping_source)->toBe(MappingSource::Suggested);
});

test('aliases_map', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(['Inventory no', 'name'], [['A-1', 'Laptop']]));
    $suggestion = $session->suggestMapping();

    expect($suggestion->columns[0]->field)->toBe('asset_tag')
        ->and($suggestion->columns[0]->source)->toBe(MappingSource::Alias)
        ->and($suggestion->columns[1]->source)->toBe(MappingSource::Name)
        ->and($suggestion->isComplete())->toBeTrue();
});

test('fuzzy_matches_are_flagged_for_review', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(['Asset tags', 'Nme'], [['A-1', 'Laptop']]));
    $suggestion = $session->suggestMapping();

    expect($suggestion->columns[0]->field)->toBe('asset_tag')
        ->and($suggestion->columns[0]->isFuzzy())->toBeTrue()
        ->and($suggestion->isComplete())->toBeFalse()
        ->and($session->status())->toBe(SessionStatus::NeedsMapping);

    config()->set('imports.headers.fuzzy', false);
    expect($session->suggestMapping()->columns[0]->field)->toBeNull()
        ->and($session->suggestMapping()->unmappedRequired)->toBe(['name']);
});

test('a_saved_mapping_applies_to_an_identical_header_row', function (): void {
    $team = team();
    $user = admin($team);
    $headers = ['Inv', 'Popis', 'Kód'];

    $first = uploadAssets($team, $user, SpreadsheetFactory::csv($headers, [['A-1', 'Laptop', 'X']]));
    expect($first->status())->toBe(SessionStatus::NeedsMapping);
    $first->map(['Inv' => 'asset_tag', 'Popis' => 'name'])->saveMapping('ERP');

    $second = uploadAssets($team, $user, SpreadsheetFactory::csv($headers, [['A-2', 'Desk', 'Y']]));

    expect($second->status())->toBe(SessionStatus::Mapped)
        ->and($second->model()->mapping_source)->toBe(MappingSource::Saved)
        ->and($second->model()->mapping)->toBe([0 => 'asset_tag', 1 => 'name'])
        ->and($second->suggestMapping()->savedMapping)->toBe('ERP');
});

test('a_saved_mapping_survives_reordered_columns', function (): void {
    $team = team();
    $user = admin($team);

    uploadAssets($team, $user, SpreadsheetFactory::csv(['Inv', 'Popis'], [['A-1', 'Laptop']]))->map(['Inv' => 'asset_tag', 'Popis' => 'name'])->saveMapping('ERP');

    // Same headers, other order: the signature differs, but columns map by normalised header.
    $saved = Imports::for($team)->by($user)->mappings('assets')->find('ERP');
    expect($saved?->columns)->toBe(['inv' => 'asset_tag', 'popis' => 'name']);

    $reordered = uploadAssets($team, $user, SpreadsheetFactory::csv(['Popis', 'Inv'], [['Desk', 'A-2']]));
    $reordered->map([0 => 'name', 1 => 'asset_tag'])->preview();

    expect($reordered->counts()->valid)->toBe(1);
});

test('a_duplicate_header_must_be_mapped_by_index', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(['Name', 'Code', 'Code'], [['Laptop', 'A', 'B']]));

    expect(invalidMappingErrors(fn () => $session->map(['Name' => 'name', 'Code' => 'asset_tag'])))->toHaveKey('columns.Code');

    $session->map(['Name' => 'name', 2 => 'asset_tag'])->preview();
    expect($session->model()->mapping)->toBe([0 => 'name', 2 => 'asset_tag'])->and($session->counts()->valid)->toBe(1);
});

test('a_blank_header_row_is_rejected', function (): void {
    $team = team();

    expect(fn () => uploadAssets($team, admin($team), SpreadsheetFactory::csv([], [[null, null], ['x', 'y']])))
        ->toThrow(fn (ImportFileRejectedException $e) => expect($e->reason)->toBe(RejectionReason::NoHeader));
});

test('a_title_row_is_skipped_with_header_row_two', function (): void {
    $team = team();
    $file = SpreadsheetFactory::csv([], [['Asset export 2026'], ['Asset tag', 'Name'], ['A-1', 'Laptop']]);

    $session = Imports::for($team)->by(admin($team))->upload('assets', $file, headerRow: 2)->preview();

    expect($session->headers())->toBe(['Asset tag', 'Name'])->and($session->counts()->valid)->toBe(1);

    $session->useHeaderRow(1);
    expect($session->headers())->toBe(['Asset export 2026'])->and($session->status())->toBe(SessionStatus::NeedsMapping);
});

test('mapping_a_field_twice_is_refused', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(['A', 'B', 'C'], [['1', '2', '3']]));

    $errors = invalidMappingErrors(fn () => $session->map(['A' => 'name', 'B' => 'name', 'C' => 'nope', 9 => 'asset_tag', 'Z' => 'serial_number']));

    expect($errors)->toHaveKeys(['fields.name', 'fields.nope', 'columns.9', 'columns.Z']);
});

test('a_required_field_left_unmapped_blocks_the_preview', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(['A'], [['1']]));

    expect($session->status())->toBe(SessionStatus::NeedsMapping)
        ->and(invalidMappingErrors(fn () => $session->preview()))->toHaveKey('mapping')
        ->and(invalidMappingErrors(fn () => $session->map(['A' => 'asset_tag'])))->toHaveKey('fields.name');

    $session->map(new ColumnMapping([new MappedColumn(0, 'name')]));
    expect($session->status())->toBe(SessionStatus::Mapped);
});

test('saved_mappings_are_per_owner', function (): void {
    $mine = team();
    $theirs = team();

    Imports::for($mine)->by(admin($mine))->mappings('assets')->save('Mine', ['Inv' => 'asset_tag', 'Popis' => 'name']);

    expect(Imports::for($mine)->by(admin($mine))->mappings('assets')->all())->toHaveCount(1)
        ->and(Imports::for($theirs)->by(admin($theirs))->mappings('assets')->all())->toBe([])
        ->and(Imports::for($theirs)->by(admin($theirs))->mappings('assets')->find('Mine'))->toBeNull();

    // An identical header row in the other owner is not mapped by my mapping.
    $session = uploadAssets($theirs, admin($theirs), SpreadsheetFactory::csv(['Inv', 'Popis'], [['A', 'B']]));
    expect($session->status())->toBe(SessionStatus::NeedsMapping);
});

test('concurrent_saves_of_two_mappings_both_survive', function (): void {
    $team = team();
    $handle = Imports::for($team)->by(admin($team))->mappings('assets');

    // Two writers that each read before the other wrote: the lock serialises the read-modify-write.
    $handle->save('First', ['A' => 'name']);
    $handle->save('Second', ['B' => 'name']);
    $handle->save('First', ['C' => 'name']);

    expect(array_map(static fn ($m): string => $m->name, $handle->all()))->toBe(['First', 'Second'])
        ->and($handle->find('First')?->columns)->toBe(['c' => 'name'])
        ->and($handle->delete('Second'))->toBeTrue()
        ->and($handle->delete('Second'))->toBeFalse()
        ->and($handle->all())->toHaveCount(1);
});

test('a_saved_mapping_that_would_overflow_the_option_row_is_refused', function (): void {
    config()->set('imports.mappings.max_bytes', 1024);
    $team = team();
    $columns = [];

    foreach (['asset_tag', 'serial_number', 'name', 'status', 'purchased_on', 'price', 'insured', 'vendor', 'assignee'] as $i => $field) {
        $columns[str_repeat('A long header ', 12).$i] = $field;
    }

    expect(fn () => Imports::for($team)->by(admin($team))->mappings('assets')->save('Huge', $columns))
        ->toThrow(TooManySavedMappingsException::class);
});

test('the_number_of_saved_mappings_per_importer_is_capped', function (): void {
    config()->set('imports.mappings.max_per_importer', 2);
    $handle = Imports::for($team = team())->by(admin($team))->mappings('assets');

    $handle->save('One', ['A' => 'name']);
    $handle->save('Two', ['A' => 'name']);

    expect(fn () => $handle->save('Three', ['A' => 'name']))->toThrow(TooManySavedMappingsException::class)
        ->and(fn () => $handle->save('', ['A' => 'name']))->toThrow(InvalidSavedMappingException::class);
});

test('saved_mappings_are_unreadable_through_the_generic_options_api', function (): void {
    // With options' authorization switched on, a host's generic options UI or API is refused;
    // the package itself still reads and writes through withoutAuthorization().
    config()->set('options.authorization.enabled', true);
    $team = team();
    Imports::for($team)->by(admin($team))->mappings('assets')->save('Mine', ['A' => 'name']);
    $options = app(OptionsManager::class);

    expect(Imports::for($team)->by(admin($team))->mappings('assets')->find('Mine'))->not->toBeNull();

    expect(fn () => $options->get(SavedMappings::class, $team))->toThrow(UnauthorizedOption::class)
        ->and(fn () => $options->set(SavedMappings::class, [], $team))->toThrow(UnauthorizedOption::class)
        ->and($options->withoutAuthorization(fn (): mixed => $options->get(SavedMappings::class, $team)))->toHaveKey('assets');
});

test('disabled_mappings_refuse_with_invalid_saved_mapping', function (): void {
    config()->set('imports.mappings.enabled', false);
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(['Inv', 'name'], [['A', 'B']]));

    expect(fn () => $session->saveMapping('X'))->toThrow(InvalidSavedMappingException::class, 'Saved mappings are switched off.')
        ->and(fn () => Imports::for($team)->by(admin($team))->mappings('assets')->save('X', ['A' => 'name']))->toThrow(InvalidSavedMappingException::class, 'Saved mappings are switched off.');
});

test('saving_through_the_handle_dispatches_mapping_saved', function (): void {
    Event::fake([MappingSaved::class]);
    $team = team();
    $user = admin($team);

    Imports::for($team)->by($user)->mappings('assets')->save('ERP', ['Inv' => 'asset_tag', 'Popis' => 'name']);

    Event::assertDispatched(MappingSaved::class, fn (MappingSaved $event): bool => $event->owner->is($team)
        && $event->actor?->is($user) === true
        && $event->importer === 'assets'
        && $event->sessionId === null
        && $event->mapping->columns === ['inv' => 'asset_tag', 'popis' => 'name']);
});

test('the_handle_and_the_session_save_the_same_shape', function (): void {
    $team = team();
    $user = admin($team);
    $headers = ['Inventárne č.', 'Popis'];
    $fromSession = uploadAssets($team, $user, SpreadsheetFactory::csv($headers, [['A-1', 'Laptop']]))
        ->map(['Inventárne č.' => 'asset_tag', 'Popis' => 'name'])->saveMapping('Session');
    $fromHandle = Imports::for($team)->by($user)->mappings('assets')->save('Handle', ['Inventárne č.' => 'asset_tag', 'Popis' => 'name']);
    $session = Imports::for($team)->by($user)->mappings('assets')->find('Session');

    expect($session?->columns)->toBe($fromHandle->columns)
        ->and($session?->signature)->toBe($fromHandle->signature)
        ->and($fromSession->model()->mapping)->toBe([0 => 'asset_tag', 1 => 'name']);
});

test('a_mapping_naming_an_unknown_field_is_refused', function (): void {
    $team = team();

    expect(fn () => Imports::for($team)->by(admin($team))->mappings('assets')->save('X', ['A' => 'nope']))
        ->toThrow(InvalidSavedMappingException::class, 'The importer has no field [nope].');
});

test('a_mapping_naming_a_field_twice_is_refused', function (): void {
    $team = team();

    expect(fn () => Imports::for($team)->by(admin($team))->mappings('assets')->save('X', ['A' => 'name', 'B' => 'name']))
        ->toThrow(InvalidSavedMappingException::class, 'The field [name] is mapped twice.')
        ->and(fn () => Imports::for($team)->by(admin($team))->mappings('assets')->save('X', []))
        ->toThrow(InvalidSavedMappingException::class, 'at least one column')
        ->and(fn () => Imports::for($team)->by(admin($team))->mappings('assets')->save(str_repeat('n', 61), ['A' => 'name']))
        ->toThrow(InvalidSavedMappingException::class, '1 to 60 characters');
});

test('a_mapping_for_a_file_importer_is_refused', function (): void {
    $team = team();

    expect(fn () => Imports::for($team)->by(admin($team))->mappings('documents')->save('X', ['A' => 'name']))
        ->toThrow(UnknownImporterException::class);
});

test('a_saved_mapping_is_recognised_whatever_the_session_locale', function (): void {
    $team = team();
    $user = admin($team);
    $headers = ['Gerät Nr', 'Bezeichnung'];
    uploadAssets($team, $user, SpreadsheetFactory::csv($headers, [['A-1', 'Laptop']]))
        ->map(['Gerät Nr' => 'asset_tag', 'Bezeichnung' => 'name'])->saveMapping('DE');

    $german = Imports::for($team)->by($user)->upload('assets', SpreadsheetFactory::csv($headers, [['A-2', 'Phone']]), locale: 'de');

    expect($german->model()->mapping)->toBe([0 => 'asset_tag', 1 => 'name'])
        ->and($german->suggestMapping()->savedMapping)->toBe('DE');
});
