<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Tests\Support\XlsxBuilder;

// Dual review 2026-11-08, O-7: a data row after more than `xlsx.max_blank_rows` blank rows ended the
// sheet — the rows below were never previewed, counted or imported, and nobody was told.

it('O-7: data after a long blank gap rejects the workbook instead of vanishing', function (string $gap): void {
    config()->set('imports.xlsx.max_blank_rows', 50);
    $rows = XlsxBuilder::row(1, ['Name']).XlsxBuilder::row(2, ['first']).$gap.XlsxBuilder::row(500, ['far below']);

    $rejection = rejectionOf(fn () => displayRows(Imports::read(XlsxBuilder::make(['S' => $rows]))));

    expect($rejection->reason)->toBe(RejectionReason::DataAfterBlankRows)
        ->and($rejection->context)->toBe(['row' => 500, 'limit' => 50])
        ->and($rejection->getMessage())->toContain('500');
})->with([
    'a jump in row numbers' => [''],
    'formatted blank rows' => [implode('', array_map(static fn (int $i): string => '<row r="'.$i.'" s="1" customFormat="1"><c r="A'.$i.'" s="1"/></row>', range(3, 199)))],
]);

it('O-7: a formatted blank tail with nothing below still reads', function (): void {
    config()->set('imports.xlsx.max_blank_rows', 50);
    $rows = XlsxBuilder::row(1, ['Name']).XlsxBuilder::row(2, ['first']);

    for ($i = 3; $i < 400; $i++) {
        $rows .= '<row r="'.$i.'" s="1" customFormat="1"><c r="A'.$i.'" s="1"/></row>';
    }

    expect(displayRows(Imports::read(XlsxBuilder::make(['S' => $rows]))))->toBe([2 => ['first']]);
});
