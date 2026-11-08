<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;

// Dual review 2026-11-08, F-2: with no data rows (no chunk offsets) the rows page fell back to
// reading from the file start numbered from `header_row + 1` — the header (and any preamble) was
// listed as rows to create.

it('F-2: a header-only csv lists no rows to create', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::csv(assetHeaders(), []))->preview();

    expect($session->rows(RowFilter::creates())->paginate()->items())->toBe([])
        ->and(iterator_to_array($session->rows(RowFilter::all())->cursor()))->toBe([])
        ->and($session->rows(RowFilter::creates())->count())->toBe(0);
});

it('F-2: preamble rows above a header with no data are not rows', function (): void {
    $team = team();
    $content = "preamble a\npreamble b\n".implode(',', assetHeaders())."\n";
    $session = Imports::for($team)->by(admin($team))->upload('assets', SpreadsheetFactory::file('p.csv', $content, 'text/csv'), headerRow: 3)->preview();

    expect($session->rows(RowFilter::creates())->paginate()->items())->toBe([]);
});

it('F-2: without stored offsets neither the rows page nor the error report reads the file top', function (): void {
    $team = team();
    $content = "preamble\n".implode(',', assetHeaders())."\n".implode(',', assetRows(1)[0])."\n";
    $session = Imports::for($team)->by(admin($team))->upload('assets', SpreadsheetFactory::file('p.csv', $content, 'text/csv'), headerRow: 2)->preview();
    $session->model()->forceFill(['chunk_offsets' => []])->save();   // no stored offset to start from

    expect($session->rows(RowFilter::creates())->paginate()->items())->toBe([])
        ->and($session->errorReport(TemplateFormat::Csv)->contents())->not->toContain('preamble');
});
