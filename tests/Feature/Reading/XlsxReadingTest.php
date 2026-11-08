<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Imports\Enums\CellType;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Exceptions\SheetNotFoundException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\XlsxBuilder;

const DATE_STYLES = '<numFmts count="4"><numFmt numFmtId="164" formatCode="dd.mm.yyyy"/><numFmt numFmtId="165" formatCode="[h]:mm"/><numFmt numFmtId="166" formatCode="&quot;date&quot;@"/><numFmt numFmtId="167" formatCode="[Red]0.00"/></numFmts>'
    .'<cellXfs count="7"><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/><xf numFmtId="166"/><xf numFmtId="167"/><xf numFmtId="22"/></cellXfs>';

/**
 * @return list<Cell|null>
 */
function firstDataRow(string $path): array
{
    foreach (Imports::read($path)->rows() as $cells) {
        return $cells;
    }

    return [];
}

test('the_format_is_decided_by_content_not_extension', function (): void {
    $xlsxNamedCsv = SpreadsheetFactory::xlsx(['Name'], [['Laptop']], name: 'export.csv');
    $csvNamedXlsx = SpreadsheetFactory::csv(['Name'], [['Laptop']], name: 'export.xlsx');

    expect(Imports::read($xlsxNamedCsv)->format())->toBe(SourceFormat::Xlsx)
        ->and(Imports::read($csvNamedXlsx)->format())->toBe(SourceFormat::Csv);
});

test('legacy_and_encrypted_workbooks_are_rejected_clearly', function (string $fixture, RejectionReason $reason): void {
    $rejection = rejectionOf(fn () => Imports::read(__DIR__.'/../../Fixtures/'.$fixture));

    expect($rejection->reason)->toBe($reason);
})->with([
    'xls' => ['legacy.xls', RejectionReason::LegacyOrEncrypted],
    'encrypted xlsx' => ['encrypted.xlsx', RejectionReason::LegacyOrEncrypted],
    'ods' => ['sheet.ods', RejectionReason::OpenDocument],
]);

test('xlsb_and_xlsm_are_rejected_by_content_type', function (): void {
    $xlsb = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name'])], contentType: 'application/vnd.ms-excel.sheet.binary.macroEnabled.main');
    $xlsm = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name'])], contentType: 'application/vnd.ms-excel.sheet.macroEnabled.main+xml');
    $other = XlsxBuilder::zip(['[Content_Types].xml' => '<Types/>', 'a.txt' => 'x']);
    $noTypes = XlsxBuilder::zip(['a.txt' => 'x']);

    expect(rejectionOf(fn () => Imports::read($xlsb))->reason)->toBe(RejectionReason::Xlsb)
        ->and(rejectionOf(fn () => Imports::read($xlsm))->reason)->toBe(RejectionReason::MacroEnabled)
        ->and(rejectionOf(fn () => Imports::read($other))->reason)->toBe(RejectionReason::UnsupportedFormat)
        ->and(rejectionOf(fn () => Imports::read($noTypes))->reason)->toBe(RejectionReason::UnsupportedFormat);

    config()->set('imports.xlsx.allow_macro_enabled', true);
    expect(Imports::read($xlsm)->headers())->toBe(['Name']);
});

test('a_binary_file_is_rejected', function (): void {
    expect(rejectionOf(fn () => Imports::read(csvFile("Name\nab\0cd\n")))->reason)->toBe(RejectionReason::Binary);
});

test('an_empty_file_is_rejected', function (): void {
    expect(rejectionOf(fn () => Imports::read(csvFile('')))->reason)->toBe(RejectionReason::Empty);
});

test('a_utf32_file_is_rejected', function (): void {
    expect(rejectionOf(fn () => Imports::read(csvFile("\xFF\xFE\x00\x00N\x00\x00\x00")))->reason)->toBe(RejectionReason::Utf32);
});

test('rich_text_shared_strings_concatenate_runs', function (): void {
    $path = XlsxBuilder::make(
        ['S' => '<row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c></row>'],
        sharedStrings: '<si><t>Name</t></si><si><r><rPr><b/></rPr><t>Think</t></r><r><t xml:space="preserve">Pad </t></r><r><t>T14</t></r></si>',
    );

    expect(firstDataRow($path)[0]?->display())->toBe('ThinkPad T14');
});

test('phonetic_runs_are_skipped', function (): void {
    $path = XlsxBuilder::make(
        ['S' => '<row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c></row>'],
        sharedStrings: '<si><t>Name</t></si><si><t>東京</t><rPh sb="0" eb="2"><t>トウキョウ</t></rPh><phoneticPr fontId="1"/></si>',
    );

    expect(firstDataRow($path)[0]?->display())->toBe('東京');
});

test('ooxml_escapes_are_decoded', function (): void {
    $path = XlsxBuilder::make(
        ['S' => '<row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2" t="s"><v>2</v></c></row>'],
        sharedStrings: '<si><t>Name</t></si><si><t>Line_x000D_break</t></si><si><t>Literal _x005F_x000D_</t></si>',
    );

    $row = firstDataRow($path);

    expect($row[0]?->display())->toBe("Line\rbreak")->and($row[1]?->display())->toBe('Literal _x000D_');
});

test('an_out_of_range_shared_string_is_malformed', function (): void {
    $path = XlsxBuilder::make(['S' => '<row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>7</v></c></row>'], sharedStrings: ['Name']);

    expect(rejectionOf(fn () => firstDataRow($path))->reason)->toBe(RejectionReason::MalformedSpreadsheet);
});

test('every_cell_type_reads_to_its_value', function (): void {
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['A', 'B', 'C', 'D', 'E', 'F'])
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>inline</t></is></c><c r="B2" t="str"><f>CONCAT("a","b")</f><v>ab</v></c>'
        .'<c r="C2" t="b"><v>1</v></c><c r="D2" t="e"><v>#N/A</v></c><c r="E2" t="d"><v>2026-03-04T10:30:00Z</v></c><c r="F2"><v>42.5</v></c></row>']);

    $row = firstDataRow($path);

    expect($row[0]?->type)->toBe(CellType::Text)->and($row[0]?->value)->toBe('inline')
        ->and($row[1]?->value)->toBe('ab')
        ->and($row[2]?->value)->toBeTrue()
        ->and($row[3]?->type)->toBe(CellType::Error)->and($row[3]?->value)->toBe('#N/A')
        ->and($row[4]?->value)->toBeInstanceOf(CarbonImmutable::class)->and($row[4]?->display())->toBe('2026-03-04 10:30:00')
        ->and($row[5]?->type)->toBe(CellType::Number)->and($row[5]?->value)->toBe('42.5');
});

test('serial_dates_follow_the_1900_system_with_the_lotus_bug', function (): void {
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['A', 'B', 'C', 'D'])
        .'<row r="2"><c r="A2" s="1"><v>59</v></c><c r="B2" s="1"><v>60</v></c><c r="C2" s="1"><v>61</v></c><c r="D2" s="1"><v>46037</v></c></row>'], styles: DATE_STYLES);

    $row = firstDataRow($path);

    // 59 = 1900-02-28; 60 is the phantom 1900-02-29 and stays a number; 61 = 1900-03-01.
    expect($row[0]?->display())->toBe('1900-02-28')
        ->and($row[1]?->type)->toBe(CellType::Number)
        ->and($row[2]?->display())->toBe('1900-03-01')
        ->and($row[3]?->display())->toBe('2026-01-15');
});

test('a_1904_workbook_shifts_dates', function (): void {
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['A']).'<row r="2"><c r="A2" s="1"><v>0</v></c></row><row r="3"><c r="A3" s="1"><v>44575</v></c></row>'], styles: DATE_STYLES, date1904: true);

    $rows = array_values(displayRows(Imports::read($path)));

    expect($rows[0][0])->toBe('1904-01-01')->and($rows[1][0])->toBe('2026-01-15');
});

test('serial_fractions_become_times', function (): void {
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['A', 'B']).'<row r="2"><c r="A2" s="6"><v>46037.75</v></c><c r="B2" s="3"><v>0.5</v></c></row>'], styles: DATE_STYLES);

    $row = firstDataRow($path);

    expect($row[0]?->display())->toBe('2026-01-15 18:00:00')->and($row[1]?->display())->toBe('12:00:00');
});

test('custom_date_formats_are_detected_and_text_formats_are_not', function (): void {
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['A', 'B', 'C'])
        .'<row r="2"><c r="A2" s="2"><v>46037</v></c><c r="B2" s="4"><v>46037</v></c><c r="C2" s="5"><v>46037</v></c></row>'], styles: DATE_STYLES);

    $row = firstDataRow($path);

    expect($row[0]?->type)->toBe(CellType::Date)
        ->and($row[1]?->type)->toBe(CellType::Number)
        ->and($row[2]?->type)->toBe(CellType::Number);
});

test('xlsx_without_cell_references_reads_sequentially', function (): void {
    $path = XlsxBuilder::make(['S' => '<row><c t="inlineStr"><is><t>Name</t></is></c><c t="inlineStr"><is><t>Tag</t></is></c></row>'
        .'<row><c t="inlineStr"><is><t>Laptop</t></is></c><c><v>7</v></c></row><row r="5"><c r="B5"><v>9</v></c></row>']);

    expect(displayRows(Imports::read($path)))->toBe([2 => ['Laptop', '7'], 5 => [null, '9']]);
});

test('strict_ooxml_reads', function (): void {
    $path = XlsxBuilder::make(['Data' => XlsxBuilder::row(1, ['Name']).XlsxBuilder::row(2, ['Laptop'])], strict: true);

    expect(Imports::read($path)->sheet())->toBe('Data')->and(displayRows(Imports::read($path)))->toBe([2 => ['Laptop']]);
});

test('absolute_and_case_different_part_names_resolve', function (): void {
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name']).XlsxBuilder::row(2, ['Laptop'])], sharedStrings: ['x'], absoluteTargets: true, upperCaseParts: true);

    expect(displayRows(Imports::read($path)))->toBe([2 => ['Laptop']]);
});

test('formula_cells_use_cached_values_and_never_evaluate', function (): void {
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['A', 'B'])
        .'<row r="2"><c r="A2"><f>1+1</f><v>2</v></c><c r="B2"><f>NOW()</f></c></row>']);

    expect(displayRows(Imports::read($path)))->toBe([2 => ['2']]);
});

/**
 * A valid workbook with a DOCTYPE (declaring an internal and an external entity) put into `$part`.
 */
function xlsxWithDoctypeIn(string $part): string
{
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name']).XlsxBuilder::row(2, ['Laptop'])], sharedStrings: ['x'], styles: '<numFmts count="0"/>');
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName($part);
    $root = (string) preg_replace('/^<\?xml[^>]*\?>\s*<([A-Za-z]+).*$/s', '$1', $xml);
    $patched = (string) preg_replace('/\?>/', '?><!DOCTYPE '.$root.' [<!ENTITY lol "lol"><!ENTITY xxe SYSTEM "file:///etc/hosts">]>', $xml, 1);
    expect($patched)->toContain('<!DOCTYPE '.$root.' [');
    $zip->addFromString($part, $patched);
    $zip->close();

    return $path;
}

test('an_xlsx_with_a_doctype_is_rejected', function (string $part): void {
    $path = xlsxWithDoctypeIn($part);

    expect(rejectionOf(function () use ($path): void {
        $file = Imports::read($path);
        $file->headers();
        iterator_to_array($file->rows());
    })->reason)->toBe(RejectionReason::MalformedSpreadsheet);
})->with([
    'package rels' => ['_rels/.rels'],
    'workbook rels' => ['xl/_rels/workbook.xml.rels'],
    'workbook' => ['xl/workbook.xml'],
    'styles' => ['xl/styles.xml'],
    'shared strings' => ['xl/sharedStrings.xml'],
    'sheet' => ['xl/worksheets/sheet1.xml'],
]);

test('content_types_with_a_doctype_is_only_string_matched', function (): void {
    // [Content_Types].xml is never parsed as XML (the detector string-matches it), so a DOCTYPE in
    // it can declare nothing: the workbook reads as usual.
    $path = xlsxWithDoctypeIn('[Content_Types].xml');

    expect(displayRows(Imports::read($path)))->toBe([2 => ['Laptop']]);
});

test('an_external_entity_is_never_resolved', function (): void {
    $secret = sys_get_temp_dir().'/imports-xxe-'.bin2hex(random_bytes(4)).'.txt';
    file_put_contents($secret, 'TOP-SECRET-'.bin2hex(random_bytes(4)));
    $marker = (string) file_get_contents($secret);
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['&xxe;'])], doctype: '<!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file://'.$secret.'">]>');

    $rejection = rejectionOf(function () use ($path, $marker): void {
        $file = Imports::read($path);

        foreach ([...$file->headers(), ...array_merge(...array_values(displayRows($file)))] as $value) {
            expect((string) $value)->not->toContain($marker);
        }
    });

    expect($rejection->reason)->toBe(RejectionReason::MalformedSpreadsheet)
        ->and($rejection->getMessage())->not->toContain($marker);
    @unlink($secret);
});

test('parts_that_together_inflate_past_max_inflated_are_rejected', function (): void {
    $rows = XlsxBuilder::row(1, ['Name']);

    for ($i = 2; $i < 300; $i++) {
        $rows .= XlsxBuilder::row($i, ['Laptop number '.$i]);
    }

    $path = XlsxBuilder::make(['S' => $rows], sharedStrings: array_map(static fn (int $i): string => 'shared string number '.$i, range(1, 300)));
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = strlen((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
    $strings = strlen((string) $zip->getFromName('xl/sharedStrings.xml'));
    $zip->close();
    // Each part fits the per-part cap; both together do not.
    config()->set(['imports.xlsx.max_part_size' => max($sheet, $strings) + 1, 'imports.xlsx.max_inflated' => $sheet + $strings - 1]);

    expect(rejectionOf(fn () => iterator_to_array(Imports::read($path)->rows()))->reason)->toBe(RejectionReason::ZipTooLarge);
});

test('a_lying_part_size_is_stopped_while_streaming', function (): void {
    config()->set('imports.xlsx.max_part_size', 4096);
    $rows = XlsxBuilder::row(1, ['Name']);

    for ($i = 2; $i < 400; $i++) {
        $rows .= XlsxBuilder::row($i, ['Laptop number '.$i]);
    }

    $path = XlsxBuilder::make(['S' => $rows]);
    $bytes = (string) file_get_contents($path);

    // Patch the sheet's declared size down in both headers: only the inflating count can tell.
    foreach (['PK'."\x03\x04" => 22, 'PK'."\x01\x02" => 24] as $signature => $offset) {
        $at = 0;

        while (($at = strpos($bytes, $signature, $at)) !== false) {
            $nameLength = unpack('v', substr($bytes, $at + ($signature === "PK\x03\x04" ? 26 : 28), 2))[1];
            $name = substr($bytes, $at + ($signature === "PK\x03\x04" ? 30 : 46), $nameLength);

            if (str_contains($name, 'worksheets/sheet1')) {
                $bytes = substr_replace($bytes, pack('V', 100), $at + $offset, 4);
            }

            $at++;
        }
    }

    file_put_contents($path, $bytes);

    $rejection = rejectionOf(fn () => iterator_to_array(Imports::read($path)->rows()));

    expect($rejection->reason)->toBe(RejectionReason::ZipTooLarge);
});

test('formatted_blank_tails_are_ignored', function (): void {
    config()->set('imports.xlsx.max_blank_rows', 50);
    $rows = XlsxBuilder::row(1, ['Name']).XlsxBuilder::row(2, ['Laptop']);

    for ($i = 3; $i < 200; $i++) {
        $rows .= '<row r="'.$i.'" s="1" customFormat="1"><c r="A'.$i.'" s="1"/></row>';
    }

    $rows .= XlsxBuilder::row(500, ['Far away']);

    expect(displayRows(Imports::read(XlsxBuilder::make(['S' => $rows]))))->toBe([2 => ['Laptop']]);
});

test('styled_empty_columns_do_not_count_against_the_limit', function (): void {
    config()->set('imports.limits.columns', 3);
    $row = '<row r="2"><c r="A2" t="inlineStr"><is><t>Laptop</t></is></c><c r="XFD2" s="1"/></row>';

    expect(displayRows(Imports::read(XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name']).$row]))))->toBe([2 => ['Laptop']]);
});

test('hidden_sheets_are_selectable_by_name_only', function (): void {
    $path = XlsxBuilder::make([
        'Secret' => ['xml' => XlsxBuilder::row(1, ['Hidden']), 'state' => 'hidden'],
        'Visible' => XlsxBuilder::row(1, ['Shown']),
    ]);

    expect(Imports::read($path)->headers())->toBe(['Shown'])
        ->and(Imports::read($path, sheet: 1)->headers())->toBe(['Shown'])
        ->and(Imports::read($path, sheet: 'secret')->headers())->toBe(['Hidden'])
        ->and(fn () => Imports::read($path, sheet: 2))->toThrow(SheetNotFoundException::class);

    $sheets = Imports::read($path)->sheets();
    expect($sheets[0]->visible)->toBeFalse()->and($sheets[0]->index)->toBeNull()->and($sheets[1]->index)->toBe(1);
});

test('chart_sheets_are_skipped', function (): void {
    $path = XlsxBuilder::make(['Chart' => ['xml' => '', 'chart' => true], 'Data' => XlsxBuilder::row(1, ['Name'])]);

    expect(Imports::read($path)->sheet())->toBe('Data')->and(Imports::read($path)->sheets())->toHaveCount(1);
});

test('an_unknown_sheet_lists_the_available_names', function (): void {
    $path = SpreadsheetFactory::xlsx(['Name'], [], sheet: 'Assets', extraSheets: ['Vendors' => [['Name']]]);

    try {
        Imports::read($path, sheet: 'Nope');
        $this->fail('Expected SheetNotFoundException');
    } catch (SheetNotFoundException $exception) {
        expect($exception->available)->toBe(['Assets', 'Vendors'])->and($exception->getMessage())->toContain('Assets, Vendors');
    }
});

test('the_public_reader_streams_rows_without_a_session', function (): void {
    $file = Imports::read(SpreadsheetFactory::xlsx(['Name', 'Price'], [['Laptop', 1299.5], ['Desk', 300]], sheet: 'Assets'));

    expect($file->format())->toBe(SourceFormat::Xlsx)
        ->and($file->sheet())->toBe('Assets')
        ->and($file->displayHeaders())->toBe(['Name', 'Price'])
        ->and($file->count())->toBe(2)
        ->and(displayRows($file))->toBe([2 => ['Laptop', '1299.5'], 3 => ['Desk', '300']]);

    $file->close();
});

test('a_blank_header_cell_displays_its_column_letter', function (): void {
    $file = Imports::read(csvFile("Name,,Tag\nLaptop,x,A-1\n"));

    expect($file->displayHeaders())->toBe(['Name', 'Column B', 'Tag']);
});
