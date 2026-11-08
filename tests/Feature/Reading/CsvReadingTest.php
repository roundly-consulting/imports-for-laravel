<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Enums\DelimiterSource;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Reading\SpreadsheetFile;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\XlsxBuilder;

/**
 * @return array<int, list<string|null>>
 */
function displayRows(SpreadsheetFile $file): array
{
    $rows = [];

    foreach ($file->rows() as $number => $cells) {
        $rows[$number] = array_map(static fn (?Cell $cell): ?string => $cell?->display(), $cells);
    }

    return $rows;
}

function csvFile(string $content): string
{
    $path = XlsxBuilder::path('csv');
    file_put_contents($path, $content);

    return $path;
}

function rejectionOf(Closure $read): ImportFileRejectedException
{
    try {
        $read();
    } catch (ImportFileRejectedException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a rejection.');
}

test('a_utf16_csv_is_rejected_naming_csv_utf8', function (): void {
    $rejection = rejectionOf(fn () => Imports::read(__DIR__.'/../../Fixtures/utf16le.csv'));

    expect($rejection->reason)->toBe(RejectionReason::Utf16)
        ->and($rejection->getMessage())->toContain('CSV UTF-8');
});

test('an_ascii_csv_is_accepted', function (): void {
    $file = Imports::read(csvFile("Name,Tag\nLaptop,A-1\n"));

    expect($file->encoding())->toBe('utf-8')->and(displayRows($file))->toBe([2 => ['Laptop', 'A-1']]);
});

test('an_invalid_sequence_across_a_block_boundary_reports_its_line', function (): void {
    // ~64 KB of valid lines, then an invalid byte right where the first block ends.
    $lines = '';

    for ($i = 1; strlen($lines) < 65530; $i++) {
        $lines .= "row {$i},ok\n";
    }

    $line = substr_count($lines, "\n") + 2;
    $content = "Name,Value\n".$lines."bad \x9Aline,x\n";

    $rejection = rejectionOf(fn () => Imports::read(csvFile($content)));

    expect($rejection->reason)->toBe(RejectionReason::NotUtf8)
        ->and($rejection->context['line'])->toBe($line)
        ->and($rejection->getMessage())->toContain('Windows-1250');
});

test('a_multibyte_char_split_across_blocks_is_valid', function (): void {
    // Put a 3-byte character so that it straddles byte 65536.
    $prefix = "Name\n".str_repeat('a', 65535 - 5 - 1)."\n";
    $content = $prefix.'€uro'."\n";

    expect(substr($content, 65535, 3))->toBe('€');

    $file = Imports::read(csvFile($content));
    expect(array_values(displayRows($file))[1][0])->toBe('€uro');
});

test('an_excel_sep_hint_sets_the_delimiter', function (): void {
    $file = Imports::read(csvFile("sep=|\nName|Tag\nLaptop|A-1\n"));

    expect($file->delimiter())->toBe('|')
        ->and($file->delimiterSource())->toBe(DelimiterSource::Hint)
        ->and($file->headers())->toBe(['Name', 'Tag'])
        ->and(displayRows($file))->toBe([2 => ['Laptop', 'A-1']]);
});

test('quoted_fields_keep_delimiters_quotes_and_newlines', function (): void {
    $file = Imports::read(csvFile("Name,Note\n\"Desk, oak\",\"He said \"\"hi\"\"\nand left\"\nChair,plain\n"));

    expect(displayRows($file))->toBe([2 => ['Desk, oak', "He said \"hi\"\nand left"], 3 => ['Chair', 'plain']]);
});

test('the_sniffer_prefers_the_consistent_delimiter', function (): void {
    // Commas appear inside values, but every record has the same number of semicolons.
    $file = Imports::read(csvFile("Name;Price;Note\nDesk;1,50;a, b\nChair;2,00;c, d\nLamp;3,25;e\n"));

    expect($file->delimiter())->toBe(';')
        ->and($file->delimiterSource())->toBe(DelimiterSource::Sniffed)
        ->and($file->headers())->toBe(['Name', 'Price', 'Note']);
});

test('a_single_column_file_reads', function (): void {
    $file = Imports::read(csvFile("Email\na@example.com\nb@example.com\n"));

    expect($file->delimiter())->toBe(',')->and(displayRows($file))->toBe([2 => ['a@example.com'], 3 => ['b@example.com']]);
});

test('an_overridden_delimiter_rereads_headers', function (): void {
    $path = csvFile("Name;Tag,Extra\nLaptop;A-1,x\n");

    expect(Imports::read($path)->headers())->toBe(['Name;Tag', 'Extra'])
        ->and(Imports::read($path, delimiter: ';')->headers())->toBe(['Name', 'Tag,Extra'])
        ->and(Imports::read($path, delimiter: ';')->delimiterSource())->toBe(DelimiterSource::Override);
});

test('a_cr_only_csv_reads_line_by_line', function (): void {
    $file = Imports::read(csvFile("Name,Tag\rLaptop,A-1\rDesk,A-2\r"));

    expect(displayRows($file))->toBe([2 => ['Laptop', 'A-1'], 3 => ['Desk', 'A-2']]);
});

test('missing_trailing_cells_are_empty', function (): void {
    $file = Imports::read(csvFile("Name,Tag,Note\nLaptop\nDesk,A-2\n"));

    expect(displayRows($file))->toBe([2 => ['Laptop'], 3 => ['Desk', 'A-2']]);
});

test('blank_records_are_skipped_but_keep_their_row_numbers', function (): void {
    $file = Imports::read(csvFile("Name,Tag\n\nLaptop,A-1\n,,\nDesk,A-2\n"));

    expect(array_keys(displayRows($file)))->toBe([3, 5]);
});

test('a_utf8_bom_is_skipped', function (): void {
    $file = Imports::read(SpreadsheetFactory::csv(['Name'], [['Laptop']], bom: true));

    expect($file->encoding())->toBe('utf-8-bom')->and($file->headers())->toBe(['Name']);
});

test('a_neutralised_cell_reimports_without_the_apostrophe', function (): void {
    $path = csvFile("Name,Formula\nLaptop,'=SUM(A1)\nDesk,'plain\n");

    expect(displayRows(Imports::read($path)))->toBe([2 => ['Laptop', '=SUM(A1)'], 3 => ['Desk', "'plain"]]);

    config()->set('imports.csv.unescape_formula_prefix', false);
    expect(displayRows(Imports::read($path))[2][1])->toBe("'=SUM(A1)");
});
