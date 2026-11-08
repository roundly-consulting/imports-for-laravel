<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;

// Dual review 2026-11-08, F-8: the error report of a file batch looked its importer up as a row
// importer and threw "There is no importer [documents]".

it('F-8: a file batch has an error report, one row per refused file', function (TemplateFormat $format): void {
    $team = team();
    $session = Imports::for($team)->by(admin($team))->uploadFiles('documents', [SpreadsheetFactory::file('a.exe', 'x'), SpreadsheetFactory::file('b.pdf', 'y')])->preview();
    $report = $session->errorReport($format);

    expect($session->counts()->invalid)->toBe(1)->and($report->filename)->toEndWith('-errors.'.$format->value);

    if ($format === TemplateFormat::Csv) {
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($report->contents(), "\u{FEFF}")))));

        expect($lines)->toHaveCount(2)
            ->and($lines[0])->toBe('File,Errors')
            ->and($lines[1])->toStartWith('a.exe,')
            ->and($lines[1])->not->toContain('b.pdf');
    }
})->with([TemplateFormat::Csv, TemplateFormat::Xlsx]);

// Dual review 2026-11-08, O-12 = F-7: the error report endpoint validated `locale` and ignored it.

it('O-12: the error report honours a requested locale for its own labels', function (): void {
    $team = team();
    $rows = assetRows(2);
    $rows[1][2] = '';
    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();

    $english = $session->errorReport(TemplateFormat::Csv)->contents();
    $slovak = $session->errorReport(TemplateFormat::Csv, 'sk')->contents();

    expect($english)->toContain(',Errors')
        ->and($slovak)->toContain(',Chyby')
        ->and($slovak)->toContain('Asset tag');   // the uploaded headers stay as uploaded
});
