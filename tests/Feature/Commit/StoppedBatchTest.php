<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Actions\Commit\FailImportAction;
use RoundlyConsulting\Imports\Actions\Commit\ProcessChunkAction;
use RoundlyConsulting\Imports\Actions\Rollback\FinishRollbackAction;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportFailed;
use RoundlyConsulting\Imports\Events\ImportStarted;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use Workbench\App\Models\Asset;

afterEach(fn () => CarbonImmutable::setTestNow());

test('a_chunk_of_an_abandoned_batch_writes_nothing', function (): void {
    config()->set('imports.queue.connection', 'database');
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();

    CarbonImmutable::setTestNow(now()->addHours(7));   // the queue is slow, not lost: the jobs are still there
    expect(Imports::sweep()->failedCommits)->toBe(1);

    drainQueue();

    expect(Asset::query()->count())->toBe(0)
        ->and(ImportedRecord::query()->count())->toBe(0)
        ->and($batch->model()->refresh()->status)->toBe(BatchStatus::Failed);
});

test('sweep_cancels_the_job_batch_it_abandons', function (): void {
    config()->set('imports.queue.connection', 'database');
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();

    CarbonImmutable::setTestNow(now()->addHours(7));
    Imports::sweep();

    expect(Bus::findBatch((string) $batch->model()->refresh()->laravel_batch_id)?->cancelled())->toBeTrue();
});

test('a_chunk_of_a_failed_batch_writes_nothing', function (): void {
    config()->set('imports.queue.connection', 'database');
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();
    // Failed by hand, leaving its job batch alone: only the chunk claim stands between the job and the rows.
    $batch->model()->forceFill(['status' => BatchStatus::Failed])->save();

    app(ProcessChunkAction::class)->execute($batch->id(), 0, true);

    expect(Asset::query()->count())->toBe(0)->and($batch->model()->refresh()->chunks_completed)->toBe(0);
});

test('two_chains_after_a_resume_write_each_row_once', function (): void {
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.commit.chunk_size', 2);
    $team = team();
    $user = admin($team);
    $batch = uploadAssets($team, $user, assetsCsv(assetRows(6)))->preview()->commit();

    app(FailImportAction::class)->execute($batch->id(), 'chunk_failed');
    // Simulate a cancel that did not reach the old chain: both chains are queued now.
    DB::table('job_batches')->update(['cancelled_at' => null]);
    Imports::for($team)->by($user)->batch($batch->id())->resume();

    drainQueue();

    expect(Asset::query()->count())->toBe(6)
        ->and(ImportedRecord::query()->count())->toBe(6)
        ->and($batch->model()->refresh()->status)->toBe(BatchStatus::Completed);
});

test('a_failed_dispatch_releases_the_lock_at_once', function (): void {
    Event::fake([ImportStarted::class, ImportFailed::class]);
    $team = team();
    $user = admin($team);
    $session = uploadAssets($team, $user, assetsCsv(assetRows(2)))->preview();
    $next = uploadAssets($team, $user, assetsCsv(assetRows(2, 10)))->preview();
    $this->mock(BatchRepository::class)->shouldReceive('store')->andThrow(new RuntimeException('queue down'));

    expect(fn () => $session->commit())->toThrow(RuntimeException::class, 'queue down');

    $model = $session->model()->refresh();
    expect($model->status)->toBe(SessionStatus::Failed)
        ->and($model->lock_key)->toBeNull()
        ->and($model->batch?->failure['reason'] ?? null)->toBe('dispatch_failed');
    Event::assertDispatched(ImportFailed::class, fn (ImportFailed $event): bool => $event->reason === 'dispatch_failed');

    $this->app->forgetInstance(BatchRepository::class);
    $this->app->offsetUnset(BatchRepository::class);
    Bus::fake();

    expect($next->commit()->status())->toBe(BatchStatus::Running);
});

test('import_started_is_followed_by_import_failed_when_the_dispatch_fails', function (): void {
    $events = [];
    Event::listen(ImportStarted::class, function () use (&$events): void {
        $events[] = 'started';
    });
    Event::listen(ImportFailed::class, function () use (&$events): void {
        $events[] = 'failed';
    });
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    $this->mock(BatchRepository::class)->shouldReceive('store')->andThrow(new RuntimeException('queue down'));

    expect(fn () => $session->commit())->toThrow(RuntimeException::class);
    expect($events)->toBe(['started', 'failed']);
});

test('a_rollback_chunk_of_a_finished_rollback_touches_nothing', function (): void {
    $team = team();
    $user = admin($team);
    $batch = uploadAssets($team, $user, assetsCsv(assetRows(3)))->preview()->commit();
    config()->set(['imports.queue.connection' => 'database', 'imports.preview.sync_threshold' => 1]);

    $batch->rollback();
    app(FinishRollbackAction::class)->execute($batch->id(), 'abandoned');
    drainQueue();

    expect(Asset::query()->count())->toBe(3)
        ->and(ImportedRecord::query()->whereNotNull('rolled_back_at')->count())->toBe(0);
});
