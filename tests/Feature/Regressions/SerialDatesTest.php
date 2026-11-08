<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\XlsxBuilder;
use RoundlyConsulting\Imports\Xlsx\SerialDate;
use Workbench\App\Models\Asset;

// Dual review 2026-11-08, O-4: the time of day was added as elapsed seconds to local midnight, so
// on a DST-change day noon read as 13:00 (spring) or 11:00 (autumn).

it('O-4: serial times keep their wall clock on DST-change days', function (string $serial, string $expected): void {
    expect(SerialDate::toDate($serial, false, 'Europe/Bratislava')?->format('Y-m-d H:i:s'))->toBe($expected)
        ->and(SerialDate::toDate($serial, false, 'America/New_York')?->format('Y-m-d H:i:s'))->toBe($expected)
        ->and(SerialDate::toDate($serial, false, 'UTC')?->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'spring forward' => ['45382.5', '2024-03-31 12:00:00'],
    'fall back' => ['45592.5', '2024-10-27 12:00:00'],
    'late evening' => ['45592.9375', '2024-10-27 22:30:00'],
]);

it('O-4: a date-time cell on a DST-change day reads as its wall clock', function (): void {
    config()->set('imports.values.timezone', 'Europe/Bratislava');
    $upload = SpreadsheetFactory::xlsx(['When'], [[CarbonImmutable::create(2024, 3, 31, 12, 0, 0, 'UTC')]]);

    $file = Imports::read($upload);
    $rows = displayRows($file);
    $file->close();

    expect($rows)->toBe([2 => ['2024-03-31 12:00:00']]);
});

// Dual review 2026-11-08, O-10: a number in a 1904 workbook whose column is not date-formatted was
// converted with the 1900 epoch (4 years and a day early) when mapped to a date field.

it('O-10: a plain number in a 1904 workbook converts with the 1904 epoch', function (): void {
    $team = team();
    $xml = XlsxBuilder::row(1, ['Asset tag', 'Name', 'Purchased on']).XlsxBuilder::row(2, ['T-1', 'One', 45000]);
    $path = XlsxBuilder::make(['S' => $xml], date1904: true);
    $file = SpreadsheetFactory::file('d.xlsx', (string) file_get_contents($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    Imports::for($team)->by(admin($team))->upload('assets', $file)
        ->map(['Asset tag' => 'asset_tag', 'Name' => 'name', 'Purchased on' => 'purchased_on'])
        ->preview()->commit();

    expect((string) Asset::query()->value('purchased_on'))->toStartWith('2027-03-16');
});
