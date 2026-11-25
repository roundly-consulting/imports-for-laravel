<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Imports\Enums\CellType;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Xlsx\InputMessage;
use RoundlyConsulting\Imports\Xlsx\SheetLayout;
use RoundlyConsulting\Imports\Xlsx\XlsxWriter;

afterEach(function (): void {
    foreach (WriterRoots::$roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($root);
    }

    WriterRoots::$roots = [];
});

final class WriterRoots
{
    /** @var list<string> */
    public static array $roots = [];
}

/**
 * A fresh target path and work directory, removed after the test.
 *
 * @return array{string, string}
 */
function writerPaths(): array
{
    $root = sys_get_temp_dir().'/imports-writer-'.bin2hex(random_bytes(6));
    mkdir($root.'/work', 0700, true);
    WriterRoots::$roots[] = $root;

    return [$root.'/out.xlsx', $root.'/work'];
}

/**
 * @return list<list<Cell|null>>
 */
function writtenRows(string $path, ?string $sheet = null): array
{
    $file = Imports::read($path, sheet: $sheet);
    $rows = array_values(iterator_to_array($file->rows()));
    $file->close();

    return $rows;
}

function writtenEntry(string $path, string $name): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $content = (string) $zip->getFromName($name);
    $zip->close();

    return $content;
}

test('it_writes_several_sheets_in_one_file', function (): void {
    [$path, $work] = writerPaths();
    $writer = new XlsxWriter($path, $work);
    $writer->addSheet('Activities', [['Name'], ['Payroll'], ['CRM']]);
    $writer->addSheet('Recipients', [['Name'], ['Tax office']]);
    $writer->close();

    $file = Imports::read($path);

    expect(array_map(static fn ($sheet): string => $sheet->name, $file->sheets()))->toBe(['Activities', 'Recipients'])
        ->and(array_map(static fn (array $row): string => $row[0]?->display() ?? '', writtenRows($path, 'Activities')))->toBe(['Payroll', 'CRM'])
        ->and(Imports::read($path, sheet: 'Recipients')->headers())->toBe(['Name'])
        ->and(writtenRows($path, 'Recipients')[0][0]?->value)->toBe('Tax office');
});

test('it_lays_out_a_header_row', function (): void {
    [$path, $work] = writerPaths();
    $writer = new XlsxWriter($path, $work);
    $writer->addSheet('Register', [['Activity', 'Purpose'], ['Payroll', 'Pay staff']], new SheetLayout(
        boldHeader: true,
        freezeHeader: true,
        widths: [0 => 30, 1 => 45.5],
        prompts: [1 => new InputMessage('Purpose', 'Why the data is processed')],
        validationRows: 100,
    ));
    $writer->close();

    $sheet = writtenEntry($path, 'xl/worksheets/sheet1.xml');

    expect(Imports::read($path)->headers())->toBe(['Activity', 'Purpose'])
        ->and($sheet)->toContain('<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>')
        ->and($sheet)->toContain('<col min="1" max="1" width="30" customWidth="1"/>')
        ->and($sheet)->toContain('<col min="2" max="2" width="45.5" customWidth="1"/>')
        ->and($sheet)->toContain('<c r="A1" s="1" t="inlineStr">')
        ->and($sheet)->toContain('<c r="A2" t="inlineStr">')
        ->and($sheet)->toContain('promptTitle="Purpose" prompt="Why the data is processed" sqref="B2:B101"')
        ->and(writtenEntry($path, 'xl/styles.xml'))->toContain('<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>');
});

test('it_writes_date_and_decimal_formats', function (): void {
    [$path, $work] = writerPaths();
    $writer = new XlsxWriter($path, $work);
    $writer->addSheet('Dates', [
        ['Reviewed on', 'Changed at', 'Fee', 'Count'],
        [CarbonImmutable::parse('2026-05-25'), new DateTimeImmutable('2026-11-25 14:30:00'), 1234.5, 7],
        [new DateTime('1999-12-31'), null, 0.125, -3],
    ], new SheetLayout(boldHeader: true, formats: [0 => 'date', 1 => 'datetime', 2 => 'decimal:2']));
    $writer->close();

    $rows = writtenRows($path);

    expect($rows[0][0]?->type)->toBe(CellType::Date)
        ->and($rows[0][0]?->display())->toBe('2026-05-25')
        ->and($rows[0][1]?->type)->toBe(CellType::Date)
        ->and($rows[0][1]?->display())->toBe('2026-11-25 14:30:00')
        ->and($rows[0][2]?->type)->toBe(CellType::Number)
        ->and($rows[0][2]?->value)->toBe('1234.5')
        ->and($rows[0][3]?->value)->toBe('7')
        ->and($rows[1][0]?->display())->toBe('1999-12-31')
        ->and($rows[1][1])->toBeNull()
        ->and($rows[1][2]?->value)->toBe('0.125')
        ->and($rows[1][3]?->value)->toBe('-3')
        ->and(writtenEntry($path, 'xl/styles.xml'))->toContain('formatCode="yyyy-mm-dd"')
        ->and(writtenEntry($path, 'xl/styles.xml'))->toContain('formatCode="yyyy-mm-dd hh:mm"')
        ->and(writtenEntry($path, 'xl/styles.xml'))->toContain('formatCode="0.00"')
        ->and(XlsxWriter::serial(new DateTimeImmutable('2026-05-25 12:00')))->toBe(46167.5);
});

test('it_writes_unicode_text_verbatim', function (bool $sharedStrings): void {
    [$path, $work] = writerPaths();
    $text = 'Ľúbica Ďurčová — spracúvanie osobných údajov: ôsmy ťažký žľab, äčšie číslo 💡';
    $writer = new XlsxWriter($path, $work, $sharedStrings);
    $writer->addSheet('Záznamy činností', [['Meno'], [$text], ['  medzery  ']]);
    $writer->close();

    $rows = writtenRows($path);

    expect(Imports::read($path)->sheets()[0]->name)->toBe('Záznamy činností')
        ->and($rows[0][0]?->value)->toBe($text)
        ->and($rows[1][0]?->value)->toBe('  medzery  ');
})->with(['inline strings' => false, 'shared strings' => true]);

test('it_writes_an_empty_sheet_and_an_empty_workbook', function (): void {
    [$path, $work] = writerPaths();
    $writer = new XlsxWriter($path, $work);
    $writer->addSheet('Empty', []);
    $writer->addSheet('Data', [['Name'], ['x']]);
    $writer->close();

    expect(array_map(static fn ($sheet): string => $sheet->name, Imports::read($path)->sheets()))->toBe(['Empty', 'Data'])
        ->and(writtenEntry($path, 'xl/worksheets/sheet1.xml'))->toContain('<sheetData/>');

    $empty = dirname($path).'/empty.xlsx';
    $writer = new XlsxWriter($empty, $work);
    $writer->close();

    expect(array_map(static fn ($sheet): string => $sheet->name, Imports::read($empty)->sheets()))->toBe(['Sheet1']);
});

test('it_never_writes_a_string_as_a_formula', function (bool $sharedStrings): void {
    [$path, $work] = writerPaths();
    $dangerous = ['=1+1', '+41 44 668 18 00', '-2+3', '@SUM(A1:A2)', '=HYPERLINK("https://evil.example","Click")', "=cmd|' /C calc'!A0", "\t=1+1", '=1+1;'];
    $writer = new XlsxWriter($path, $work, $sharedStrings);
    $writer->addSheet('Text', [['Value'], ...array_map(static fn (string $value): array => [$value], $dangerous)], new SheetLayout(boldHeader: true));
    $writer->close();

    $sheet = writtenEntry($path, 'xl/worksheets/sheet1.xml');

    expect($sheet)->not->toContain('<f>')
        ->and($sheet)->not->toContain('<f ')
        ->and(substr_count($sheet, $sharedStrings ? 't="s"' : 't="inlineStr"'))->toBe(count($dangerous) + 1)
        ->and(array_map(static fn (array $row): mixed => $row[0]?->value, writtenRows($path)))->toBe($dangerous)
        ->and(array_map(static fn (array $row): ?CellType => $row[0]?->type, writtenRows($path)))->each->toBe(CellType::Text);
})->with(['inline strings' => false, 'shared strings' => true]);

test('it_streams_10_000_rows_without_holding_them', function (): void {
    config()->set('imports.limits.rows', 20_000);
    [$path, $work] = writerPaths();
    $writer = new XlsxWriter($path, $work);
    $growth = null;
    $flushed = 0;

    $rows = (static function () use ($work, &$growth, &$flushed): Generator {
        yield ['Activity', 'Purpose', 'Reviewed on'];
        $before = memory_get_usage();

        for ($i = 1; $i <= 10_000; $i++) {
            if ($i === 9_500) {
                clearstatcache();
                $flushed = (int) filesize($work.'/sheet1.xml');
                $growth = memory_get_usage() - $before;
            }

            yield ['Activity '.$i, str_repeat('Processing purpose text ', 4), new DateTimeImmutable('2026-01-01')];
        }
    })();

    $writer->addSheet('Big', $rows, new SheetLayout(boldHeader: true, formats: [2 => 'date']));
    $writer->close();

    $file = Imports::read($path);

    expect($flushed)->toBeGreaterThan(1_000_000)
        ->and($growth)->toBeLessThan(1024 * 1024)
        ->and($file->count())->toBe(10_000)
        ->and(writtenRows($path)[9_999][0]?->value)->toBe('Activity 10000');
});

test('the_facade_gives_a_writer_with_a_temp_work_directory_it_cleans_up', function (): void {
    [$path] = writerPaths();
    $before = is_dir(ImportsConfig::tmpPath()) ? count((array) scandir(ImportsConfig::tmpPath())) : 2;

    $writer = Imports::xlsx($path);
    $writer->addSheet('Register', [['Name'], ['Payroll']]);

    expect(count((array) scandir(ImportsConfig::tmpPath())))->toBe($before + 1);

    $writer->close();

    expect(count((array) scandir(ImportsConfig::tmpPath())))->toBe($before)
        ->and(writtenRows($path)[0][0]?->value)->toBe('Payroll')
        ->and(XlsxWriter::temporary($path))->toBeInstanceOf(XlsxWriter::class);
});

test('a_failed_close_still_removes_the_temp_work_directory', function (): void {
    $before = is_dir(ImportsConfig::tmpPath()) ? count((array) scandir(ImportsConfig::tmpPath())) : 2;
    $writer = Imports::xlsx(sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(4)).'/out.xlsx');
    $writer->addSheet('Register', [['Name']]);

    expect(fn () => $writer->close())->toThrow(RuntimeException::class)
        ->and(count((array) scandir(ImportsConfig::tmpPath())))->toBe($before);
});
