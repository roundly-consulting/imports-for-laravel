<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Pipeline\CellNormalizer;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Support\Numbers;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;

function normalize(Field $field, ?Cell $cell, string $locale = 'en'): mixed
{
    $result = CellNormalizer::forSession($locale, 'Europe/Bratislava')->normalize($field, $cell);

    return $result->failed() ? 'error:'.$result->error : $result->value;
}

test('sk_decimals_parse_with_a_comma', function (): void {
    $price = Field::decimal('price', 2);

    expect(normalize($price, Cell::text('1 299,5'), 'sk'))->toBe('1299.50')
        ->and(normalize($price, Cell::text("1\u{00A0}299,50"), 'sk'))->toBe('1299.50')
        ->and(normalize($price, Cell::text('1.234,5'), 'sk'))->toBe('1234.50')
        ->and(normalize($price, Cell::text('1,234.5'), 'en'))->toBe('1234.50')
        ->and(normalize($price, Cell::text('12.5'), 'sk'))->toBe('12.50')
        ->and(normalize($price, Cell::text('1.234'), 'sk'))->toBe('1234.00')
        ->and(normalize($price, Cell::text('abc'), 'sk'))->toBe('error:invalid_number')
        ->and(normalize($price, Cell::text('1.2.3,4'), 'sk'))->toBe('error:invalid_number');
});

test('float_noise_rounds_to_the_field_scale', function (): void {
    expect(normalize(Field::decimal('price', 2), Cell::number('0.30000000000000004')))->toBe('0.30')
        ->and(normalize(Field::decimal('price', 2), Cell::number('2.675')))->toBe('2.68')
        ->and(normalize(Field::decimal('price', 0), Cell::number('9.5')))->toBe('10')
        ->and(normalize(Field::decimal('price', 2), Cell::number('-0.004')))->toBe('0.00')
        ->and(normalize(Field::decimal('price', 1), Cell::number('99.96')))->toBe('100.0');
});

test('scientific_numbers_become_plain_strings', function (): void {
    expect(normalize(Field::text('code'), Cell::number('1.2345678901234E+15')))->toBe('1234567890123400')
        ->and(normalize(Field::text('code'), Cell::number('5.0')))->toBe('5')
        ->and(normalize(Field::decimal('n', 3), Cell::number('1.5E-3')))->toBe('0.002')
        ->and(Numbers::plain('-0'))->toBe('0')
        ->and(Numbers::plain('.'))->toBeNull();
});

test('numeric_cells_in_text_fields_lose_no_digits_we_can_keep', function (): void {
    // A long id typed as text keeps every digit and its leading zeros; a number cell keeps what
    // the file stored (Excel keeps 15 significant digits).
    expect(normalize(Field::text('id'), Cell::text('00012345678901234567')))->toBe('00012345678901234567')
        ->and(normalize(Field::text('id'), Cell::number('123456789012345')))->toBe('123456789012345')
        ->and(normalize(Field::text('flag'), Cell::boolean(true)))->toBe('TRUE')
        ->and(normalize(Field::text('when'), Cell::date(CarbonImmutable::parse('2026-01-15'))))->toBe('2026-01-15');
});

test('integers_accept_whole_numbers_only', function (): void {
    $qty = Field::integer('qty');

    expect(normalize($qty, Cell::number('5.0')))->toBe(5)
        ->and(normalize($qty, Cell::text('1 000'), 'sk'))->toBe(1000)
        ->and(normalize($qty, Cell::text('5.5')))->toBe('error:invalid_integer')
        ->and(normalize($qty, Cell::text('99999999999999999999')))->toBe('error:integer_overflow');
});

test('an_impossible_date_is_a_cell_error', function (): void {
    $date = Field::date('on');

    expect(normalize($date, Cell::text('31.02.2026')))->toBe('error:invalid_date')
        ->and(normalize($date, Cell::text('15.01.2026'))?->toDateString())->toBe('2026-01-15')
        ->and(normalize($date, Cell::text('15. 1. 2026'))?->toDateString())->toBe('2026-01-15')
        ->and(normalize($date, Cell::number('46037'))?->toDateString())->toBe('2026-01-15')
        ->and(normalize($date, Cell::number('0')))->toBe('error:invalid_date')
        ->and(normalize(Field::datetime('at'), Cell::text('2026-01-15 09:30'))?->format('Y-m-d H:i'))->toBe('2026-01-15 09:30')
        ->and(normalize(Field::datetime('at'), Cell::text('2026-01-15'))?->format('Y-m-d H:i'))->toBe('2026-01-15 00:00')
        ->and(normalize(Field::datetime('at'), Cell::text('nope')))->toBe('error:invalid_datetime')
        ->and(normalize(Field::datetime('at'), Cell::number('46037.5'))?->format('H:i'))->toBe('12:00');
});

test('ambiguous_slash_dates_are_refused_by_default', function (): void {
    expect(normalize(Field::date('on'), Cell::text('01/02/2026')))->toBe('error:invalid_date')
        ->and(normalize(Field::date('on')->formats('m/d/Y'), Cell::text('01/02/2026'))?->toDateString())->toBe('2026-01-02');
});

test('booleans_accept_english_and_slovak_words', function (): void {
    $flag = Field::boolean('flag')->trueValues('ja')->falseValues('nein');

    foreach (['1', 'true', 'Yes', 'y', 'x', '✓', 'áno', 'ANO', 'a', 'pravda', 'ja'] as $word) {
        expect(normalize($flag, Cell::text($word)))->toBeTrue($word);
    }

    foreach (['0', 'false', 'no', 'N', 'nie', 'nepravda', 'nein'] as $word) {
        expect(normalize($flag, Cell::text($word)))->toBeFalse($word);
    }

    expect(normalize($flag, Cell::boolean(false)))->toBeFalse()
        ->and(normalize($flag, Cell::number('1')))->toBeTrue()
        ->and(normalize($flag, Cell::text('maybe')))->toBe('error:invalid_boolean');
});

test('a_choice_matches_its_value_or_label_case_insensitively', function (): void {
    $status = Field::choice('status')->options(['active' => ['en' => 'Active', 'sk' => 'Aktívny'], 'retired' => 'Retired']);

    expect(normalize($status, Cell::text('ACTIVE')))->toBe('active')
        ->and(normalize($status, Cell::text('aktívny'), 'en'))->toBe('active')
        ->and(normalize($status, Cell::text('Retired')))->toBe('retired')
        ->and(normalize($status, Cell::text('gone')))->toBe('error:invalid_choice')
        ->and(normalize(Field::choice('n')->options(['1', '2']), Cell::number('2.0')))->toBe('2');
});

test('the_value_pipeline_trims_normalizes_and_defaults_in_order', function (): void {
    $calls = [];
    $field = Field::text('code')->normalizeUsing(static function (mixed $value, ?Cell $cell) use (&$calls): mixed {
        $calls[] = $value;

        return is_string($value) ? strtoupper($value) : $value;
    })->default('NONE');

    expect(normalize($field, Cell::text("\u{00A0}\u{200B} ab-1 \u{202F}")))->toBe('AB-1')
        ->and(normalize($field, null))->toBe('NONE')
        ->and(normalize($field, Cell::text('   ')))->toBe('NONE')
        ->and($calls)->toBe(['ab-1', null, null])
        ->and(normalize(Field::text('x'), Cell::error('#REF!')))->toBe('error:spreadsheet_error')
        ->and(normalize(Field::email('mail'), Cell::text('Ada@Example.COM')))->toBe('ada@example.com');

    config()->set('imports.limits.cell_length', 5);
    expect(normalize(Field::text('x'), Cell::text('too long text')))->toBe('error:too_long');
});

test('errors_name_the_spreadsheet_row_and_column', function (): void {
    $team = team();
    $rows = assetRows(3);
    $rows[1][4] = '31.02.2026';     // row 3, column E
    $rows[2][6] = 'perhaps';        // row 4, column G

    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $invalid = $session->rows(RowFilter::invalid())->paginate()->items();

    expect(array_map(static fn ($r): array => [$r->rowNumber, $r->errors[0]->columnLetter, $r->errors[0]->field, $r->errors[0]->code], $invalid))
        ->toBe([[3, 'E', 'purchased_on', 'invalid_date'], [4, 'G', 'insured', 'invalid_boolean']])
        ->and($invalid[0]->errors[0]->message)->toBe('Purchased on: "31.02.2026" is not a valid date.')
        ->and($invalid[0]->errors[0]->value)->toBe('31.02.2026');
});

test('validation_rules_and_messages_use_labels_in_the_session_locale', function (): void {
    $team = team();
    $rows = assetRows(1);
    $rows[0][2] = str_repeat('x', 130);

    $session = Imports::for($team)->by(admin($team))->upload('assets', SpreadsheetFactory::csv(assetHeaders(), $rows), locale: 'en')->preview();
    $error = $session->rows(RowFilter::field('name'))->paginate()->items()[0]->errors[0];

    expect($error->code)->toBe('max')->and($error->message)->toContain('Name');
});
