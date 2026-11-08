<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportExpired;
use RoundlyConsulting\Imports\Events\ImportsPruned;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\Doctor;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Hooks;
use Workbench\App\Models\Asset;

afterEach(fn () => CarbonImmutable::setTestNow());

test('routes_are_off_by_default', function (): void {
    $this->getJson('/api/imports/importers')->assertNotFound();
});

test('expired_sessions_lose_their_file', function (): void {
    Event::fake([ImportExpired::class]);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(2)));
    $path = $session->model()->file_path;

    CarbonImmutable::setTestNow(now()->addDays(3));
    Artisan::call('imports:sweep');

    expect($session->status())->toBe(SessionStatus::Expired)
        ->and(Storage::disk('imports')->exists($path))->toBeFalse()
        ->and(Artisan::output())->toContain('Expired 1 session(s)');
    Event::assertDispatched(ImportExpired::class);
});

test('stuck_commits_are_failed_and_unlocked_by_sweep', function (): void {
    Bus::fake();
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    $batch->model()->forceFill(['laravel_batch_id' => 'missing-batch'])->save();

    CarbonImmutable::setTestNow(now()->addMinutes(10));
    expect(Imports::sweep()->failedCommits)->toBe(0);

    CarbonImmutable::setTestNow(now()->addMinutes(40));
    expect(Imports::sweep()->failedCommits)->toBe(1)
        ->and($batch->status())->toBe(BatchStatus::Failed)
        ->and($batch->session()->status())->toBe(SessionStatus::Failed)
        ->and($batch->session()->model()->lock_key)->toBeNull();
});

test('a_commit_whose_jobs_were_lost_is_released_after_abandon_after', function (): void {
    config()->set('imports.queue.connection', 'database');
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    DB::table('jobs')->delete();   // a flushed queue: the batch stays pending forever

    CarbonImmutable::setTestNow(now()->addHours(1));
    expect(Imports::sweep()->failedCommits)->toBe(0);

    CarbonImmutable::setTestNow(now()->addHours(7));
    expect(Imports::sweep()->failedCommits)->toBe(1)->and($batch->status())->toBe(BatchStatus::Failed)
        ->and($batch->model()->failure['reason'] ?? null)->toBe('abandoned');
});

test('stuck_rollbacks_are_failed_and_sessions_without_a_batch_are_released', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    $batch->model()->forceFill(['rollback_status' => RollbackStatus::RollingBack])->save();
    ImportSession::query()->update(['status' => SessionStatus::RollingBack, 'last_progress_at' => now()->subHours(8)]);
    $orphan = ImportSession::factory()->owner($team)->status(SessionStatus::Committing)->create(['last_progress_at' => now()->subHours(8)]);

    $report = Imports::sweep();

    expect($report->failedRollbacks)->toBe(1)->and($report->failedCommits)->toBe(1)
        ->and($orphan->refresh()->status)->toBe(SessionStatus::Failed);
});

test('sweep_removes_orphaned_temp_directories', function (): void {
    $root = ImportsConfig::tmpPath();
    @mkdir($root, 0700, true);
    $old = $root.'/1234-01JC000000000000000000000A';
    $fresh = $root.'/1234-01JC000000000000000000000B';
    $foreign = $root.'/not-ours';
    @mkdir($old.'/nested', 0700, true);
    file_put_contents($old.'/nested/part.xml', 'x');
    @mkdir($fresh);
    @mkdir($foreign);
    touch($old, time() - 7 * 3600);

    expect(Imports::sweep()->tempDirectories)->toBe(1)
        ->and(is_dir($old))->toBeFalse()
        ->and(is_dir($fresh))->toBeTrue()
        ->and(is_dir($foreign))->toBeTrue();

    @rmdir($fresh);
    @rmdir($foreign);
});

test('temp_files_are_removed_after_success_failure_and_rejection', function (): void {
    $marker = sys_get_temp_dir().'/imports-temp-'.bin2hex(random_bytes(6));
    config()->set('imports.storage.tmp_path', $marker);
    $team = team();

    // success: XLSX upload + preview (working copy) + commit
    uploadAssets($team, admin($team), SpreadsheetFactory::xlsx(assetHeaders(), assetRows(3)))->preview()->commit()->rollback();
    // rejection
    try {
        uploadAssets($team, admin($team), SpreadsheetFactory::file('x.csv', "Name\n\x9A\n"));
    } catch (Throwable) {
    }
    // failure inside a chunk
    Hooks::$onCreate = static fn (): never => throw new Error('boom');
    try {
        uploadAssets($team, admin($team), assetsCsv(assetRows(1, 50)))->preview()->commit();
    } catch (Throwable) {
    }
    // file batches and templates
    Imports::for($team)->by(admin($team))->uploadFiles('documents', SpreadsheetFactory::zip(['a.pdf' => 'x']));
    Imports::template('assets')->contents();

    expect(glob($marker.'/*') ?: [])->toBe([]);
    @rmdir($marker);
});

test('prune_keeps_the_ledger_until_history_retention', function (): void {
    Event::fake([ImportsPruned::class]);
    $team = team();
    $rows = assetRows(2);
    $rows[0][2] = '';
    $batch = uploadAssets($team, admin($team), assetsCsv($rows))->preview()->commit();
    $session = $batch->session()->model();

    CarbonImmutable::setTestNow(now()->addDays(31));
    $report = Imports::prune();

    expect($report->files)->toBe(1)->and($report->rowResults)->toBe(1)->and($report->sessions)->toBe(0)
        ->and(Storage::disk('imports')->exists($session->file_path))->toBeFalse()
        ->and($session->refresh()->file_pruned_at)->not->toBeNull()
        ->and(ImportedRecord::query()->count())->toBe(1)
        ->and(Asset::query()->firstOrFail()->wasImported())->toBeTrue();

    config()->set('imports.history.retention', 'P90D');
    expect(Imports::prune()->sessions)->toBe(0);

    CarbonImmutable::setTestNow(now()->addDays(100));
    Artisan::call('imports:prune');

    expect(ImportedRecord::query()->count())->toBe(0)
        ->and(ImportBatch::query()->count())->toBe(0)
        ->and(ImportSession::query()->withTrashed()->count())->toBe(0)
        ->and(Asset::query()->count())->toBe(1)
        ->and(Artisan::output())->toContain('Pruned');
    Event::assertDispatched(ImportsPruned::class);
});

test('a_discarded_session_loses_its_file_and_rows', function (): void {
    $team = team();
    $rows = assetRows(2);
    $rows[0][2] = '';
    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $path = $session->model()->file_path;

    $session->discard();

    expect(Storage::disk('imports')->exists($path))->toBeFalse()
        ->and(ImportRowResult::query()->count())->toBe(0)
        ->and(ImportSession::query()->count())->toBe(0)
        ->and(ImportSession::query()->withTrashed()->firstOrFail()->status)->toBe(SessionStatus::Discarded);
});

test('doctor_reports_each_misconfiguration', function (string $check, Closure $break, string $level): void {
    $break();
    $finding = collect(Doctor::run())->first(static fn (DoctorFinding $f): bool => $f->check === $check);

    expect($finding?->level)->toBe($level);
})->with([
    'importers ok' => ['importers', fn () => null, 'ok'],
    'no importers' => ['importers', fn () => config()->set('imports.importers', []), 'warning'],
    'bad importer' => ['importers', fn () => config()->set('imports.importers', [stdClass::class]), 'error'],
    'key types' => ['key_type', fn () => config()->set('options.key_type', 'ulid'), 'error'],
    'job batches' => ['job_batches', fn () => config()->set('queue.batching.table', 'missing_batches'), 'error'],
    'sync queue' => ['queue', fn () => null, 'warning'],
    'queue off' => ['queue', fn () => config()->set('imports.queue.enabled', 'off'), 'ok'],
    'retry after' => ['retry_after', fn () => config()->set(['imports.queue.connection' => 'database', 'queue.connections.database.retry_after' => 90]), 'error'],
    'disk missing' => ['disk', fn () => config()->set('imports.storage.disk', 'nope'), 'error'],
    'public disk' => ['disk', fn () => config()->set('filesystems.disks.imports.visibility', 'public'), 'error'],
    'retention' => ['retention', fn () => config()->set('imports.storage.retention', 'P1D'), 'warning'],
    'extensions' => ['extensions', fn () => null, 'ok'],
    'lock store' => ['lock_store', fn () => config()->set('cache.default', 'array'), 'warning'],
    'owner scope' => ['owner_scope', fn () => null, 'ok'],
    'migrations' => ['migrations', fn () => config()->set('imports.tables.sessions', 'nope_sessions'), 'error'],
]);

test('the_doctor_command_exits_by_its_findings', function (): void {
    config()->set('imports.queue.enabled', 'off');
    config()->set('cache.default', 'database');
    expect(Artisan::call('imports:doctor'))->toBe(0);

    config()->set('options.key_type', 'uuid');
    expect(Artisan::call('imports:doctor'))->toBe(1)->and(Artisan::output())->toContain('key_type');
});

test('the_importers_command_lists_every_importer', function (): void {
    Artisan::call('imports:importers');

    expect(Artisan::output())->toContain('assets')->toContain('name*')->toContain('skip, update, create_new')->toContain('documents')->toContain('pdf, docx');
});

test('the_template_command_writes_a_file', function (): void {
    $dir = sys_get_temp_dir().'/imports-template-'.bin2hex(random_bytes(6));
    @mkdir($dir);

    expect(Artisan::call('imports:template', ['importer' => 'people', '--format' => 'csv', '--locale' => 'sk', '--output' => $dir.'/people.csv']))->toBe(0)
        ->and((string) file_get_contents($dir.'/people.csv'))->toContain('Meno')
        ->and(Artisan::call('imports:template', ['importer' => 'people', '--format' => 'ods']))->toBe(1);

    @unlink($dir.'/people.csv');
    @rmdir($dir);
});

test('install_refuses_when_the_tables_exist', function (): void {
    expect(Artisan::call('imports:install'))->toBe(1)->and(Artisan::output())->toContain('already exists');
});
