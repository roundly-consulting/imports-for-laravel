<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Csv\CsvWriter;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;

final class LongListImporter extends Importer
{
    public function key(): string
    {
        return 'long-list';
    }

    public function label(): string
    {
        return 'Inventár / „zariadenia“: 2026';
    }

    public function model(): string
    {
        return Asset::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function fields(): array
    {
        $options = [];

        for ($i = 1; $i <= 40; $i++) {
            $options['opt'.$i] = 'Option number '.$i.', with a comma';
        }

        return [Field::text('name')->required(), Field::choice('kind')->options($options), Field::datetime('seen_at')->excludeFromTemplate()];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return new Asset;
    }
}

function zipPart(string $path, string $part): string
{
    $zip = new ZipArchive;
    $zip->open($path);
    $content = (string) $zip->getFromName($part);
    $zip->close();

    return $content;
}

test('the_template_download_has_a_safe_filename', function (): void {
    Imports::register(LongListImporter::class);
    $response = Imports::template('long-list', TemplateFormat::Xlsx, 'sk')->download();
    $named = Imports::template('long-list', TemplateFormat::Xlsx, 'sk')->download('Inventár „2026“.xlsx');

    expect((string) $response->headers->get('Content-Disposition'))->toBe('attachment; filename=inventar-zariadenia-2026.xlsx')
        ->and((string) $named->headers->get('Content-Disposition'))->toContain('filename="Inventar _2026_.xlsx"')->toContain("filename*=utf-8''Invent%C3%A1r")
        ->and($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();

    expect(substr($body, 0, 2))->toBe('PK');
});

test('an_xlsx_template_has_dropdowns_input_messages_and_a_frozen_header', function (): void {
    $path = Imports::template('assets', TemplateFormat::Xlsx, 'en')->toTemporaryFile();
    $sheet = zipPart($path, 'xl/worksheets/sheet1.xml');
    $styles = zipPart($path, 'xl/styles.xml');

    expect($sheet)->toContain('state="frozen"')
        ->toContain('<dataValidation type="list"')
        ->toContain('<formula1>&quot;Active,Retired,In repair&quot;</formula1>')
        ->toContain('<formula1>&quot;Yes,No&quot;</formula1>')
        ->toContain('promptTitle="Purchased on"')
        ->toContain('sqref="D2:D10001"')
        ->and($styles)->toContain('<b/>')->toContain('formatCode="yyyy-mm-dd"')->toContain('formatCode="0.00"')
        ->and(Imports::read($path)->headers())->toBe(['Asset tag', 'Serial number', 'Name *', 'Status', 'Purchased on', 'Price', 'Insured', 'Vendor', 'Assignee']);

    @unlink($path);
});

test('long_option_lists_use_the_hidden_lists_sheet', function (): void {
    Imports::register(LongListImporter::class);
    $path = Imports::template('long-list', TemplateFormat::Xlsx)->toTemporaryFile();

    $workbook = zipPart($path, 'xl/workbook.xml');
    $sheet = zipPart($path, 'xl/worksheets/sheet1.xml');

    expect($workbook)->toContain('name="Lists"')->toContain('state="hidden"')->toContain('<definedName name="_imports_kind">Lists!$A$1:$A$40</definedName>')
        ->and($sheet)->toContain('<formula1>_imports_kind</formula1>')
        ->and(Imports::read($path)->headers())->toBe(['name *', 'kind'])
        ->and(Imports::read($path, sheet: 'Lists')->headers())->toBe(['Option number 1, with a comma']);

    @unlink($path);
});

test('our_template_reads_back_with_our_reader_in_both_locales', function (string $locale, TemplateFormat $format): void {
    $team = team();
    $path = Imports::template('people', $format, $locale)->toTemporaryFile();

    $session = Imports::for($team)->by(admin($team))->upload('people', SpreadsheetFactory::file('people.'.$format->value, (string) file_get_contents($path)), locale: $locale)->preview();

    expect($session->model()->mapping)->toBe([0 => 'email', 1 => 'first_name', 2 => 'last_name', 3 => 'role'])
        ->and($session->counts()->templateRows)->toBe($format === TemplateFormat::Csv ? 2 : 1)
        ->and($session->counts()->total)->toBe(0);

    @unlink($path);
})->with([
    'en csv' => ['en', TemplateFormat::Csv],
    'sk csv' => ['sk', TemplateFormat::Csv],
    'en xlsx' => ['en', TemplateFormat::Xlsx],
    'sk xlsx' => ['sk', TemplateFormat::Xlsx],
]);

test('csv_templates_use_the_locale_delimiter_a_bom_and_a_hint_row', function (): void {
    $sk = Imports::template('people', TemplateFormat::Csv, 'sk')->contents();
    $en = Imports::template('people', TemplateFormat::Csv, 'en')->contents();

    $skLines = array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\r\n", trim(substr($sk, 3))));
    $enLines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\r\n", trim(substr($en, 3))));

    expect($sk)->toStartWith("\xEF\xBB\xBF")
        ->and($skLines[0])->toBe(['E-mail *', 'Meno', 'Priezvisko', 'Rola'])
        ->and($skLines[1][0])->toBe('Povinné · E-mailová adresa')
        ->and($enLines[0])->toBe(['Email *', 'First name', 'Last name', 'Role'])
        ->and($enLines[1][3])->toBe('Optional · One of: Administrator, Member, Viewer')
        ->and($enLines[2])->toBe(['jane@example.com', 'Jane', 'Doe', 'admin']);
});

test('csv_templates_neutralise_formula_like_cells', function (): void {
    expect(CsvWriter::neutralise('=SUM(A1)'))->toBe("'=SUM(A1)")
        ->and(CsvWriter::neutralise('+1 555'))->toBe("'+1 555")
        ->and(CsvWriter::neutralise('@cmd'))->toBe("'@cmd")
        ->and(CsvWriter::neutralise("\tTab"))->toBe("'\tTab")
        ->and(CsvWriter::neutralise('-cmd|calc'))->toBe("'-cmd|calc")
        ->and(CsvWriter::neutralise('plain'))->toBe('plain')
        ->and(CsvWriter::neutralise(true))->toBe('TRUE')
        ->and(CsvWriter::neutralise(null))->toBe('');
});

test('negative_numbers_are_not_prefixed', function (): void {
    expect(CsvWriter::neutralise('-5'))->toBe('-5')
        ->and(CsvWriter::neutralise('-5,25'))->toBe('-5,25')
        ->and(CsvWriter::neutralise(-3.5))->toBe('-3.5');
});

test('csv_error_reports_neutralise_uploaded_formulas', function (): void {
    $team = team();
    $rows = assetRows(2);
    $rows[0][2] = '';
    $rows[0][0] = '=HYPERLINK("http://evil","x")';

    $report = uploadAssets($team, admin($team), assetsCsv($rows))->preview()->errorReport(TemplateFormat::Csv)->contents();

    expect($report)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK');
});

test('the_error_report_contains_only_failing_rows_plus_an_errors_column', function (TemplateFormat $format): void {
    $team = team();
    $rows = assetRows(4);
    $rows[1][2] = '';
    $rows[3][4] = '31.02.2026';
    $source = $format === TemplateFormat::Csv ? assetsCsv($rows) : SpreadsheetFactory::xlsx(assetHeaders(), $rows);

    $template = uploadAssets($team, admin($team), $source)->preview()->errorReport($format);
    $path = $template->toTemporaryFile();
    $file = Imports::read($path);
    $read = [];

    foreach ($file->rows() as $cells) {
        $read[] = array_map(static fn ($c): ?string => $c?->display(), $cells);
    }

    expect($file->headers())->toBe([...assetHeaders(), 'Errors'])
        ->and(array_column($read, 0))->toBe(['AST-0002', 'AST-0004'])
        ->and($read[0][9])->toContain('Name')
        ->and($read[1][9])->toContain('31.02.2026')
        ->and($template->filename)->toBe('import-errors.'.$format->value);

    @unlink($path);
})->with([TemplateFormat::Csv, TemplateFormat::Xlsx]);

test('a_template_can_be_stored_on_a_disk', function (): void {
    $path = Imports::template('assets', TemplateFormat::Csv)->store('imports', 'templates/assets.csv');

    expect(Storage::disk('imports')->exists($path))->toBeTrue()
        ->and(Imports::importer('assets')->template(TemplateFormat::Csv)->filename)->toBe('assets.csv');
});
