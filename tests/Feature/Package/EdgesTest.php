<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RoundlyConsulting\Imports\Actions\Commit\ProcessChunkAction;
use RoundlyConsulting\Imports\Actions\Preview\ValidateRowsAction;
use RoundlyConsulting\Imports\Actions\Rollback\RollbackChunkAction;
use RoundlyConsulting\Imports\Csv\CsvReader;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSource;
use RoundlyConsulting\Imports\DataTransferObjects\RollbackReport;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\FileDuplicates;
use RoundlyConsulting\Imports\Definition\Limits;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\CellType;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportPreviewFailed;
use RoundlyConsulting\Imports\Events\ImportPreviewProgressed;
use RoundlyConsulting\Imports\Exceptions\ImportAlreadyRunningException;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Http\AuthenticatedUserOwner;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Jobs\CommitChunkJob;
use RoundlyConsulting\Imports\Jobs\RollbackChunkJob;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Pipeline\CellNormalizer;
use RoundlyConsulting\Imports\Pipeline\KeyValues;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Reading\FormatDetector;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use RoundlyConsulting\Imports\Support\Digests;
use RoundlyConsulting\Imports\Support\ImportLock;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\XlsxBuilder;
use RoundlyConsulting\Imports\Xlsx\CellReference;
use RoundlyConsulting\Imports\Xlsx\SerialDate;
use RoundlyConsulting\Imports\Zip\ZipGuard;
use Workbench\App\Importers\DocumentsImporter;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Invitation;
use Workbench\Database\Factories\InvitationFactory;

final class NamesImporter extends Importer
{
    public function key(): string
    {
        return 'names';
    }

    public function label(): string
    {
        return 'Names';
    }

    public function model(): string
    {
        return Invitation::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function naturalKey(): NaturalKey
    {
        return NaturalKey::by('first_name', 'last_name')->caseInsensitive();
    }

    public function fields(): array
    {
        return [Field::text('first_name'), Field::text('last_name'), Field::email('email')->required()];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return Invitation::query()->create([...$row->all(), 'role' => 'member', ...$context->ownerAttributes()]);
    }
}

beforeEach(function (): void {
    // A disk that is not the `local` driver: sources and working copies are copied, not opened in place.
    $root = sys_get_temp_dir().'/imports-remote-'.bin2hex(random_bytes(5));
    Storage::extend('remote-like', static fn ($app, array $config): FilesystemAdapter => new FilesystemAdapter(new Filesystem($adapter = new LocalFilesystemAdapter($config['root'])), $adapter, $config));
    config()->set('filesystems.disks.remote', ['driver' => 'remote-like', 'root' => $root]);
});

test('a_disk_that_is_not_local_is_copied_for_reading', function (): void {
    config()->set('imports.storage.disk', 'remote');
    $team = team();

    $session = uploadAssets($team, admin($team), SpreadsheetFactory::xlsx(assetHeaders(), assetRows(3)))->preview();
    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(3)
        ->and(Storage::disk('remote')->exists((string) $session->model()->working_copy_path))->toBeTrue();

    Storage::disk('remote')->put('incoming/a.csv', "Name\nLaptop\n");
    $file = Imports::read(ImportSource::disk('remote', 'incoming/a.csv'));

    expect($file->headers())->toBe(['Name'])
        ->and(ImportSource::disk('remote', 'incoming/a.csv')->declaredSize())->toBe(12)
        ->and(ImportSource::disk('remote', 'incoming/a.csv', 'Nice.csv')->originalName())->toBe('Nice.csv');
});

test('import_sources_from_paths_and_their_names', function (): void {
    $path = csvFile("Name\nx\n");

    expect(ImportSource::path($path)->declaredSize())->toBe(7)
        ->and(ImportSource::path('/missing/file.csv')->declaredSize())->toBeNull()
        ->and(ImportSource::path("/tmp/evil\x01/../name.csv", "../../a\0b.csv")->originalName())->toBe('.._.._a_b.csv')
        ->and(ImportSource::path('/tmp/')->originalName())->toBe('tmp')
        ->and(ImportSource::from(ImportSource::path($path))->path)->toBe($path)
        ->and(fn () => ImportSource::path('/missing/file.csv')->localPath(sys_get_temp_dir()))->toThrow(ImportFileRejectedException::class)
        ->and(fn () => ImportSource::disk('remote', 'missing.csv')->localPath(sys_get_temp_dir()))->toThrow(ImportFileRejectedException::class);
});

test('composite_case_insensitive_natural_keys_match', function (): void {
    Imports::register(NamesImporter::class);
    $team = team();
    InvitationFactory::new()->create(['team_id' => $team->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'old@example.com']);

    $session = Imports::for($team)->by(admin($team))->upload('names', SpreadsheetFactory::csv(['first_name', 'last_name', 'email'], [
        ['ADA', 'lovelace', 'ada@example.com'],
        ['Alan', 'Turing', 'alan@example.com'],
        ['Grace', '', 'grace@example.com'],
    ]))->preview();

    expect($session->counts()->matched)->toBe(1)->and($session->counts()->valid)->toBe(2);
});

test('import_rows_expose_their_values', function (): void {
    $row = new ImportRow(5, ['name' => 'Laptop', 'tag' => null, 'qty' => 0], ['name' => 'Laptop'], ['name' => Cell::text('Laptop')], ['vendor' => null], ['name']);

    expect($row->number())->toBe(5)
        ->and($row->get('missing', 'fallback'))->toBe('fallback')
        ->and($row->has('name'))->toBeTrue()->and($row->has('tag'))->toBeFalse()
        ->and($row->filled())->toBe(['name' => 'Laptop', 'qty' => 0])
        ->and($row->only('name'))->toBe(['name' => 'Laptop'])
        ->and($row->except('name', 'qty'))->toBe(['tag' => null])
        ->and($row->raw('name'))->toBe('Laptop')->and($row->raw('tag'))->toBeNull()
        ->and($row->cell('name')?->type)->toBe(CellType::Text)
        ->and($row->reference('vendor'))->toBeNull();
});

test('ledger_relations_and_the_host_trait_read_the_import_source', function (): void {
    $team = team();
    $user = admin($team);
    $batch = uploadAssets($team, $user, assetsCsv(assetRows(2)))->preview()->commit();
    $asset = Asset::query()->firstOrFail();
    $ledger = ImportedRecord::query()->firstOrFail();
    $model = $batch->model()->refresh();

    expect($ledger->batch->is($model))->toBeTrue()
        ->and($ledger->session->id)->toBe($model->session_id)
        ->and($ledger->record?->is($asset))->toBeTrue()
        ->and($asset->importBatch()?->is($model))->toBeTrue()
        ->and(Asset::query()->importedIn($model)->count())->toBe(2)
        ->and($model->actor?->is($user))->toBeTrue()
        ->and($model->rollbackActor)->toBeNull()
        ->and($model->session->owner->is($team))->toBeTrue();

    $rows = assetRows(1);
    $rows[0][2] = '';
    uploadAssets($team, $user, assetsCsv($rows))->preview();

    expect(ImportRowResult::query()->firstOrFail()->session->importer)->toBe('assets');
});

test('the_default_owner_resolver_is_the_authenticated_user', function (): void {
    $user = admin(team());
    $request = Request::create('/');

    expect((new AuthenticatedUserOwner)->resolve($request))->toBeNull();

    $request->setUserResolver(static fn () => $user);
    expect((new AuthenticatedUserOwner)->resolve($request)?->is($user))->toBeTrue();
});

test('a_preview_that_cannot_read_its_file_fails_and_says_why', function (): void {
    Event::fake([ImportPreviewFailed::class]);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(2)));
    Storage::disk('imports')->delete($session->model()->file_path);

    expect(fn () => $session->preview())->toThrow(ImportStateException::class)
        ->and($session->status())->toBe(SessionStatus::PreviewFailed)
        ->and($session->model()->failure['message'] ?? null)->toContain('no longer available');
    Event::assertDispatched(ImportPreviewFailed::class);
});

test('a_stale_validation_run_writes_nothing_and_stops', function (): void {
    config()->set('imports.preview.progress_every', 1);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)));
    $session->model()->forceFill(['status' => SessionStatus::Validating, 'preview_version' => 4])->save();

    // A run of an older version returns at once; a missing session too.
    app(ValidateRowsAction::class)->execute($session->id(), 3);
    app(ValidateRowsAction::class)->execute('999999', 4);

    // A run whose version moves while it validates stops at its next progress tick.
    ImportSession::saving(static function (ImportSession $s): void {});
    Event::listen(ImportPreviewProgressed::class, static function () use ($session): void {
        ImportSession::query()->whereKey($session->id())->update(['preview_version' => 9]);
    });
    app(ValidateRowsAction::class)->execute($session->id(), 4);

    expect($session->status())->toBe(SessionStatus::Validating)->and($session->counts()->total)->toBe(0);
});

test('lock_conflicts_explain_themselves', function (): void {
    Bus::fake();
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    $model = $session->model();

    expect(fn () => ImportLock::acquire($model, [SessionStatus::Previewed], SessionStatus::Committing, $model->preview_version + 1))
        ->toThrow(ImportStateException::class, 'out of date')
        ->and(fn () => ImportLock::acquire($model, [SessionStatus::Completed], SessionStatus::RollingBack))
        ->toThrow(ImportStateException::class);

    ImportLock::acquire($model, [SessionStatus::Previewed], SessionStatus::Committing);
    $model->forceFill(['status' => SessionStatus::Previewed])->save();

    expect(fn () => ImportLock::acquire($model->refresh(), [SessionStatus::Previewed], SessionStatus::Committing))
        ->toThrow(ImportAlreadyRunningException::class);
});

test('jobs_return_early_once_their_batch_was_cancelled', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    [$commit] = (new CommitChunkJob($batch->id(), 0))->withFakeBatch(cancelledAt: CarbonImmutable::now());
    [$rollback] = (new RollbackChunkJob($batch->id(), 1, 1))->withFakeBatch(cancelledAt: CarbonImmutable::now());

    $commit->handle(app(ProcessChunkAction::class));
    $rollback->handle(app(RollbackChunkAction::class));
    app(RollbackChunkAction::class)->execute('999999', 1, 2);
    app(ProcessChunkAction::class)->execute('999999', 0);

    expect(Asset::query()->count())->toBe(1);
});

test('the_manager_refuses_a_container_that_resolves_another_class', function (): void {
    app()->bind(ValidateRowsAction::class, static fn (): stdClass => new stdClass);

    expect(fn () => app(ImportsManager::class)->perform('x', ValidateRowsAction::class, static fn (): null => null))->toThrow(LogicException::class);
});

test('config_values_are_read_strictly', function (string $key, mixed $value, Closure $read): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidImportsConfigurationException::class, $key);
})->with([
    'tmp path' => ['imports.storage.tmp_path', ['x'], fn () => ImportsConfig::tmpPath()],
    'delimiters' => ['imports.csv.delimiters', ['ab'], fn () => ImportsConfig::csvDelimiters()],
    'delimiters type' => ['imports.csv.delimiters', 5, fn () => ImportsConfig::csvDelimiters()],
    'timezone' => ['imports.values.timezone', 'Mars/Base', fn () => ImportsConfig::timezone()],
    'backoff' => ['imports.commit.backoff', ['soon'], fn () => ImportsConfig::backoff()],
    'csv delimiter map' => ['imports.templates.csv_delimiter', 'semicolon', fn () => ImportsConfig::csvTemplateDelimiter('sk')],
    'csv delimiter value' => ['imports.templates.csv_delimiter', ['default' => ';;'], fn () => ImportsConfig::csvTemplateDelimiter('en')],
    'owner scope' => ['imports.owner_scope', stdClass::class, fn () => ImportsConfig::ownerScope()],
    'owner resolver' => ['imports.routes.owner_resolver', stdClass::class, fn () => ImportsConfig::ownerResolver()],
    'importers' => ['imports.importers', ['Nope\\Missing'], fn () => ImportsConfig::importers()],
    'duration' => ['imports.undo.window', 'a week', fn () => ImportsConfig::undoWindow()],
    'duration type' => ['imports.undo.window', 7, fn () => ImportsConfig::undoWindow()],
    'zero duration' => ['imports.sessions.stale_after', 'PT0S', fn () => ImportsConfig::staleAfter()],
    'queue name' => ['imports.queue.queue', 5, fn () => ImportsConfig::queueName()],
]);

test('config_values_take_their_defaults_and_lenient_forms', function (): void {
    config()->set('imports.csv.delimiters', null);
    config()->set('imports.commit.backoff', '5');
    config()->set('imports.templates.csv_delimiter', null);
    config()->set('imports.values.timezone', 'Europe/Bratislava');
    config()->set('imports.record_key_type', 'uuid');
    config()->set('imports.history.retention', 'P1Y');

    expect(ImportsConfig::csvDelimiters())->toBe([',', ';'])
        ->and(ImportsConfig::backoff())->toBe([5])
        ->and(ImportsConfig::csvTemplateDelimiter('sk'))->toBe(';')
        ->and(ImportsConfig::csvTemplateDelimiter('en'))->toBe(',')
        ->and(ImportsConfig::timezone())->toBe('Europe/Bratislava')
        ->and(ImportsConfig::keyTypeKeyFor('record'))->toBe('imports.record_key_type')
        ->and(ImportsConfig::historyRetention()?->totalDays)->toBeGreaterThan(300);

    config()->set('imports.values.timezone', null);
    config()->set('app.timezone', '');
    expect(ImportsConfig::timezone())->toBe('UTC');
});

test('format_detection_refuses_unreadable_and_oversized_files', function (): void {
    expect(fn () => FormatDetector::detect('/missing/file.csv', 1000))->toThrow(ImportFileRejectedException::class)
        ->and(rejectionOf(fn () => FormatDetector::detect(csvFile(str_repeat('x', 2048)), 1024))->reason)->toBe(RejectionReason::TooLarge)
        ->and(rejectionOf(fn () => FormatDetector::detect(csvFile("\x00\x00\xFE\xFFdata"), 1024))->reason)->toBe(RejectionReason::Utf32)
        ->and(rejectionOf(fn () => FormatDetector::detect(csvFile("\xFE\xFFdata"), 1024))->reason)->toBe(RejectionReason::Utf16)
        ->and(rejectionOf(fn () => FormatDetector::detect(csvFile("PK\x03\x04 not really a zip"), 1024))->reason)->toBe(RejectionReason::UnsupportedFormat);
});

test('workbook_containers_are_checked_before_reading', function (): void {
    config()->set('imports.xlsx.max_entries', 3);
    $tooMany = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name'])]);

    expect(rejectionOf(fn () => Imports::read($tooMany))->reason)->toBe(RejectionReason::MalformedSpreadsheet);

    config()->set('imports.xlsx.max_entries', 1000);
    config()->set('imports.xlsx.max_part_size', 1024);
    $bigPart = XlsxBuilder::make(['S' => XlsxBuilder::row(1, [str_repeat('x', 4000)])]);
    expect(rejectionOf(fn () => Imports::read($bigPart))->reason)->toBe(RejectionReason::ZipTooLarge);

    config()->set('imports.xlsx.max_part_size', 268435456);
    $encrypted = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name'])], extraParts: ['x.bin' => 'secret']);
    $zip = new ZipArchive;
    $zip->open($encrypted);
    $zip->setEncryptionName('x.bin', ZipArchive::EM_AES_256, 'pw');
    $zip->close();
    expect(rejectionOf(fn () => Imports::read($encrypted))->reason)->toBe(RejectionReason::LegacyOrEncrypted)
        ->and(rejectionOf(fn () => ZipGuard::open(csvFile('not a zip'), RejectionReason::ZipInconsistent))->reason)->toBe(RejectionReason::ZipInconsistent);

    $noWorkbook = XlsxBuilder::zip(['[Content_Types].xml' => '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>']);
    $noSheets = XlsxBuilder::make([], extraParts: []);
    $broken = XlsxBuilder::make(['S' => '<row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c></row><row r="2"><c']);

    expect(rejectionOf(fn () => Imports::read($noWorkbook))->reason)->toBe(RejectionReason::MalformedSpreadsheet)
        ->and(rejectionOf(fn () => Imports::read($noSheets))->reason)->toBe(RejectionReason::MalformedSpreadsheet)
        ->and(rejectionOf(fn () => iterator_to_array(Imports::read($broken)->rows()))->reason)->toBe(RejectionReason::MalformedSpreadsheet);
});

test('xlsx_cells_of_every_type_survive_the_working_copy', function (): void {
    $team = team();
    $styles = '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs>';
    $path = XlsxBuilder::make(['S' => XlsxBuilder::row(1, ['Name', 'Insured', 'Purchased on', 'Asset tag'])
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>Laptop</t></is></c><c r="B2" t="b"><v>1</v></c><c r="C2" s="1"><v>46037</v></c><c r="D2" t="e"><v>#REF!</v></c></row>'
        .'<row r="3"><c r="A3" t="inlineStr"><is><t>Desk</t></is></c><c r="B3" t="b"><v>0</v></c><c r="C3" t="d"><v>2026-01-15</v></c><c r="D3"><v>12345</v></c></row>'], styles: $styles);

    $session = uploadAssets($team, admin($team), SpreadsheetFactory::file('book.xlsx', (string) file_get_contents($path)))->preview();
    $batch = $session->commit();

    expect($session->counts()->invalid)->toBe(1)
        ->and($session->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->code)->toBe('spreadsheet_error')
        ->and($batch->summary()->created)->toBe(1)
        ->and(Asset::query()->firstOrFail()->asset_tag)->toBe('12345')
        ->and(Asset::query()->firstOrFail()->purchased_on)->toStartWith('2026-01-15');
});

test('small_value_helpers_cover_their_edges', function (): void {
    $normalizer = CellNormalizer::forSession('en', 'UTC');

    expect($normalizer->normalize(Field::integer('n')->normalizeUsing(static fn (): int => 7), Cell::text('x'))->value)->toBe(7)
        ->and($normalizer->normalize(Field::decimal('n')->normalizeUsing(static fn (): float => 1.25), Cell::text('x'))->value)->toBe('1.25')
        ->and($normalizer->normalize(Field::text('n')->normalizeUsing(static fn (): array => []), Cell::text('x'))->error)->toBe('invalid_value')
        ->and($normalizer->normalize(Field::email('n')->normalizeUsing(static fn (): array => []), Cell::text('x'))->error)->toBe('invalid_value')
        ->and($normalizer->normalize(Field::choice('n')->options(['a']), Cell::boolean(true))->error)->toBe('invalid_choice')
        ->and($normalizer->normalize(Field::boolean('n')->normalizeUsing(static fn (): array => []), Cell::text('x'))->error)->toBe('invalid_boolean')
        ->and($normalizer->normalize(Field::date('n')->normalizeUsing(static fn (): array => []), Cell::text('x'))->error)->toBe('invalid_date')
        ->and($normalizer->normalize(Field::date('n'), Cell::date(CarbonImmutable::parse('2026-01-15 10:00')))->value?->toDateTimeString())->toBe('2026-01-15 00:00:00')
        ->and($normalizer->normalize(Field::text('n'), Cell::number('abc'))->value)->toBe('abc')
        ->and($normalizer->normalize(Field::text('n')->normalizeUsing(static fn (): float => 2.5), Cell::text('x'))->value)->toBe('2.5')
        ->and(KeyValues::of([]))->toBeNull()
        ->and(KeyValues::of(true))->toBe('1')
        ->and(CellReference::column('1A'))->toBeNull()
        ->and(CellReference::row('B12'))->toBe(12)->and(CellReference::row('B'))->toBeNull()
        ->and(SerialDate::toDate('-1', false, 'UTC'))->toBeNull()
        ->and(SerialDate::toDate('abc', false, 'UTC'))->toBeNull()
        ->and(SerialDate::toDate('0.9999999', false, 'UTC')?->format('Y-m-d H:i:s'))->toBe('1900-01-01 00:00:00')
        ->and((new RollbackReport(keptChanged: 1, keptByHook: 2, linked: 3))->keptTotal())->toBe(6)
        ->and(Limits::fromConfig()->withRows(500_000)->rows)->toBe(100_000)
        ->and(Limits::fromConfig()->withColumns(0)->columns)->toBe(1)
        ->and(Limits::fromConfig()->withFileSize(5)->fileSizeBytes())->toBe(5120)
        ->and(FileDuplicates::none()->strategy)->toBe('none')
        ->and(RowFilter::status(RowStatus::Matched)->statuses)->toBe([RowStatus::Matched])
        ->and(Digests::stream(fopen('data://text/plain,abc', 'rb')))->toBe(hash('sha256', 'abc'));
});

test('a_file_importer_may_find_duplicates_with_its_own_callback_or_none', function (): void {
    $team = team();
    $seen = [];
    DocumentsImporter::$duplicates = FileDuplicates::using(static function (string $sha, Model $owner) use (&$seen): ?Model {
        $seen[] = $sha;

        return $sha === Digests::hex('known') ? $owner : null;
    });

    $session = Imports::for($team)->by(admin($team))->uploadFiles('documents', [SpreadsheetFactory::file('a.pdf', 'known'), SpreadsheetFactory::file('b.pdf', 'new')])->preview();
    expect($session->counts()->matched)->toBe(1)->and($seen)->toHaveCount(2);

    DocumentsImporter::$duplicates = FileDuplicates::none();
    expect(Imports::for($team)->by(admin($team))->uploadFiles('documents', SpreadsheetFactory::file('a.pdf', 'known'))->preview()->counts()->valid)->toBe(1);
    DocumentsImporter::$duplicates = null;
});

test('spreadsheet_factory_encodes_csv_files', function (): void {
    $utf16 = SpreadsheetFactory::csv(['Name'], [['x']], bom: true, encoding: 'UTF-16LE');
    $utf16be = SpreadsheetFactory::csv(['Name'], [['x']], bom: true, encoding: 'UTF-16BE');
    $cp1252 = SpreadsheetFactory::csv(['Name'], [['café']], encoding: 'Windows-1252');
    $cp1250 = SpreadsheetFactory::csv(['Name'], [['Štúr', true]], encoding: 'Windows-1250');

    expect(substr((string) file_get_contents($utf16->getRealPath()), 0, 2))->toBe("\xFF\xFE")
        ->and(substr((string) file_get_contents($utf16be->getRealPath()), 0, 2))->toBe("\xFE\xFF")
        ->and((string) file_get_contents($cp1252->getRealPath()))->toContain("caf\xE9")
        ->and((string) file_get_contents($cp1250->getRealPath()))->toContain("\x8At\xFAr,TRUE");
});

test('a_csv_reader_closed_twice_is_harmless_and_refuses_to_read', function (): void {
    $reader = new CsvReader(csvFile("Name\nx\n"), null, 10);
    $reader->close();
    $reader->close();

    expect(fn () => iterator_to_array($reader->rows()))->toThrow(ImportFileRejectedException::class)
        ->and(fn () => new CsvReader('/missing.csv', null, 10))->toThrow(ImportFileRejectedException::class);
});

test('a_template_left_unused_removes_its_temp_directory', function (): void {
    $marker = sys_get_temp_dir().'/imports-unused-'.bin2hex(random_bytes(5));
    config()->set('imports.storage.tmp_path', $marker);

    (function (): void {
        Imports::template('assets');
    })();
    gc_collect_cycles();

    expect(glob($marker.'/*') ?: [])->toBe([]);
    @rmdir($marker);
});

test('an_unknown_session_or_batch_status_is_refused_cleanly', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    $batch = $session->commit();

    expect(fn () => $session->preview())->toThrow(ImportStateException::class)
        ->and(fn () => $session->map(['Name' => 'name']))->toThrow(ImportStateException::class)
        ->and(fn () => $session->discard())->toThrow(ImportStateException::class)
        ->and(fn () => $session->useSheet(1))->toThrow(ImportStateException::class)
        ->and(fn () => $session->mode(DuplicateMode::Update))->toThrow(ImportStateException::class)
        ->and(fn () => $batch->resume())->toThrow(ImportStateException::class)
        ->and(ImportBatch::query()->count())->toBe(1);
});
