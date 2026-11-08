<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportFailed;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Hooks;
use Workbench\App\Models\Asset;

// claude.ai chat review 2026-11-08 — session state and housekeeping (C-2, C-3, C-9, C-12).

/**
 * C-2: a previewed session on the database queue, and a commit that starts between a pre-commit
 * transition's checks and its write — the transition's own authorize() call is the window.
 */
function commitDuringTransition(Closure $transition): SessionHandle
{
    $team = team();
    $user = admin($team);
    $rows = assetRows(4);
    $rows[3][2] = '';   // one invalid row, so the plan has a row result to lose
    $session = uploadAssets($team, $user, assetsCsv($rows))->preview();
    $id = $session->id();
    config(['queue.default' => 'database']);
    $fired = false;

    Hooks::$authorize = static function (string $operation) use (&$fired, $team, $user, $id): bool {
        if ($operation === 'upload' && ! $fired) {
            $fired = true;
            Imports::for($team)->by($user)->session($id)->commit();
        }

        return true;
    };

    expect(static fn () => $transition($session))->toThrow(ImportStateException::class);

    return $session;
}

/**
 * The commit the transition raced is untouched: still running under its lock with its plan, and
 * draining the queue imports every row.
 */
function expectCommitUntouched(SessionHandle $session): void
{
    $model = ImportSession::query()->withTrashed()->findOrFail($session->id());

    expect($model->status)->toBe(SessionStatus::Committing)
        ->and($model->lock_key)->not->toBeNull()
        ->and($model->chunk_offsets)->not->toBeNull()
        ->and($model->trashed())->toBeFalse()
        ->and(Storage::disk($model->file_disk)->exists($model->file_path))->toBeTrue()
        ->and(DB::table('import_row_results')->where('session_id', $model->getKey())->count())->toBe(1);

    drainQueue();

    expect($model->refresh()->status)->toBe(SessionStatus::Completed)
        ->and($model->lock_key)->toBeNull()
        ->and(Asset::query()->count())->toBe(3);
}

it('C-2: a remap that loses the race to a commit is refused', function (): void {
    expectCommitUntouched(commitDuringTransition(static fn (SessionHandle $s) => $s->map(['Asset tag' => 'asset_tag', 'Serial number' => 'serial_number', 'Name' => 'name'])));
});

it('C-2: a discard that loses the race to a commit is refused', function (): void {
    expectCommitUntouched(commitDuringTransition(static fn (SessionHandle $s) => $s->discard()));
});

it('C-2: a mode change that loses the race to a commit is refused', function (): void {
    $session = commitDuringTransition(static fn (SessionHandle $s) => $s->mode(DuplicateMode::Update));

    expect(ImportSession::query()->findOrFail($session->id())->mode)->toBe(DuplicateMode::Skip);
    expectCommitUntouched($session);
});

it('C-2: a source change that loses the race to a commit is refused', function (): void {
    expectCommitUntouched(commitDuringTransition(static fn (SessionHandle $s) => $s->useHeaderRow(1)));
});

it('C-2: a re-preview that loses the race to a commit is refused', function (): void {
    expectCommitUntouched(commitDuringTransition(static fn (SessionHandle $s) => $s->preview()));
});

it('C-2: imports:sweep does not expire a session a commit took meanwhile', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)));
    $id = $session->id();
    $lock = str_repeat('r', 64);   // lock_key is char(64): PostgreSQL pads anything shorter
    $fired = false;

    // A commit lands after the sweep read the session, before it expires it.
    ImportSession::retrieved(static function (ImportSession $model) use (&$fired, $id, $lock): void {
        if (! $fired && $model->getKey() === $id) {
            $fired = true;
            DB::table('import_sessions')->where('id', $id)->update(['status' => SessionStatus::Committing->value, 'lock_key' => $lock, 'last_progress_at' => now()->utc()->format('Y-m-d H:i:s')]);
        }
    });

    Carbon::setTestNow(now()->addDays(10));
    $report = Imports::sweep();
    Carbon::setTestNow();

    $model = ImportSession::query()->withTrashed()->findOrFail($id);

    expect($report->expired)->toBe(0)
        ->and($model->status)->toBe(SessionStatus::Committing)
        ->and($model->lock_key)->toBe($lock)
        ->and(Storage::disk($model->file_disk)->exists($model->file_path))->toBeTrue();
});

it('C-2: a mode change refuses a preview that moved meanwhile', function (): void {
    $team = team();
    $user = admin($team);
    $session = uploadAssets($team, $user, assetsCsv(assetRows(2)))->preview();
    $id = $session->id();
    $fired = false;

    // Another tab re-previews between the mode change's checks and its re-plan.
    Hooks::$authorize = static function (string $operation) use (&$fired, $team, $user, $id): bool {
        if ($operation === 'upload' && ! $fired) {
            $fired = true;
            Imports::for($team)->by($user)->session($id)->preview();
        }

        return true;
    };

    expect(static fn () => $session->mode(DuplicateMode::Update))->toThrow(ImportStateException::class, __('imports::exceptions.stale_preview'))
        ->and(ImportSession::query()->findOrFail($id)->mode)->toBe(DuplicateMode::Skip);
});

it('C-2: a refused re-preview keeps the working copy; an accepted one deletes it', function (): void {
    $team = team();
    $user = admin($team);
    $session = uploadAssets($team, $user, SpreadsheetFactory::xlsx(assetHeaders(), assetRows(3)))->preview();
    $copy = (string) $session->model()->refresh()->working_copy_path;

    expect(Storage::disk('imports')->exists($copy))->toBeTrue();

    $fired = false;
    Hooks::$authorize = static function (string $operation) use (&$fired, $team, $user, $session): bool {
        if ($operation === 'upload' && ! $fired) {
            $fired = true;
            Imports::for($team)->by($user)->session($session->id())->commit();
        }

        return true;
    };
    config(['queue.default' => 'database']);

    expect(static fn () => $session->preview())->toThrow(ImportStateException::class)
        ->and(Storage::disk('imports')->exists($copy))->toBeTrue();

    $other = uploadAssets($team, $user, SpreadsheetFactory::xlsx(assetHeaders(), assetRows(3, 10)))->preview();
    $otherCopy = (string) $other->model()->refresh()->working_copy_path;
    $other->map(['Asset tag' => 'asset_tag', 'Name' => 'name']);

    expect(Storage::disk('imports')->exists($otherCopy))->toBeFalse()
        ->and($other->model()->refresh()->working_copy_path)->toBeNull();
});

it('C-3: imports:sweep fails a commit whose owner was deleted, and handles every other session', function (): void {
    $a = team();
    $b = team();
    $first = uploadAssets($a, admin($a), assetsCsv(assetRows(2)))->preview();
    $second = uploadAssets($b, admin($b), assetsCsv(assetRows(2)))->preview();
    $expiring = uploadAssets($a, admin($a), assetsCsv(assetRows(2)));
    config(['queue.default' => 'database']);
    $first->commit();
    $second->commit();
    $a->delete();

    Carbon::setTestNow(now()->addDays(10));
    expect(Artisan::call('imports:sweep'))->toBe(0);
    Carbon::setTestNow();

    $sessions = ImportSession::query()->withTrashed()->get()->keyBy('id');

    expect($sessions[$first->id()]->status)->toBe(SessionStatus::Failed)
        ->and($sessions[$first->id()]->lock_key)->toBeNull()
        ->and($sessions[$second->id()]->status)->toBe(SessionStatus::Failed)
        ->and($sessions[$second->id()]->lock_key)->toBeNull()
        ->and($sessions[$expiring->id()]->status)->toBe(SessionStatus::Expired);
});

it('C-3: an undo whose owner was deleted is finished by imports:sweep', function (): void {
    config(['imports.commit.chunk_size' => 1]);
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    config(['imports.preview.sync_threshold' => 0, 'queue.default' => 'database']);
    $batch->rollback();
    $team->delete();

    Carbon::setTestNow(now()->addDays(10));
    expect(Artisan::call('imports:sweep'))->toBe(0);
    Carbon::setTestNow();

    $model = ImportBatch::query()->findOrFail($batch->id());

    expect($model->rollback_status)->toBe(RollbackStatus::RollbackFailed)
        ->and($model->session->lock_key)->toBeNull()
        ->and($model->session->status)->toBe(SessionStatus::RollbackFailed);
});

it('C-3: an error on one session is reported and the sweep carries on', function (): void {
    Exceptions::fake();
    $a = team();
    $b = team();
    $first = uploadAssets($a, admin($a), assetsCsv(assetRows(2)))->preview();
    $second = uploadAssets($b, admin($b), assetsCsv(assetRows(2)))->preview();
    config(['queue.default' => 'database']);
    $first->commit();
    $second->commit();
    $thrown = false;

    Event::listen(ImportFailed::class, static function () use (&$thrown): void {
        if (! $thrown) {
            $thrown = true;

            throw new RuntimeException('listener broke');
        }
    });

    Carbon::setTestNow(now()->addDays(10));
    $report = Imports::sweep();
    Carbon::setTestNow();

    Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'listener broke');

    expect($report->failedCommits)->toBe(1)
        ->and(ImportSession::query()->where('status', SessionStatus::Failed->value)->count())->toBe(2);
});

it('C-9: imports:sweep leaves a progressing undo with one failed chunk alone', function (): void {
    config(['imports.commit.chunk_size' => 1]);
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(4)))->preview()->commit();
    config(['imports.preview.sync_threshold' => 0, 'queue.default' => 'database']);
    $start = Carbon::now()->startOfSecond();
    Carbon::setTestNow($start);
    $batch->rollback();
    $sessionId = $batch->model()->session_id;

    // One undo chunk runs a little later, then a worker reports a failed job of the batch.
    Carbon::setTestNow($start->copy()->addMinutes(5));
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,imports', '--once' => true, '--sleep' => 0]);

    expect(ImportSession::query()->findOrFail($sessionId)->last_progress_at?->equalTo($start->copy()->addMinutes(5)))->toBeTrue()
        ->and(Asset::query()->count())->toBe(3);

    // A worker fails one chunk for good: its job leaves the queue and counts as failed.
    DB::table('jobs')->where('id', DB::table('jobs')->min('id'))->delete();
    DB::table('job_batches')->where('id', $batch->model()->refresh()->rollback_batch_id)->update(['failed_jobs' => 1, 'failed_job_ids' => json_encode(['lost'])]);

    Carbon::setTestNow($start->copy()->addMinutes(36));
    Artisan::call('imports:sweep');
    Carbon::setTestNow();

    expect(ImportSession::query()->findOrFail($sessionId)->status)->toBe(SessionStatus::RollingBack)
        ->and($batch->model()->refresh()->rollback_status)->toBe(RollbackStatus::RollingBack);

    drainQueue();

    // The other chunks ran; the batch finished with its failure, which the undo then reports.
    expect(Asset::query()->count())->toBe(1)
        ->and($batch->model()->refresh()->rollback_status)->toBe(RollbackStatus::RollbackFailed);
});

it('C-12: ImportSession::count() and ImportBatch::count() count rows; countOf() reads a count', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    $session = ImportSession::query()->findOrFail($batch->model()->session_id);

    expect(ImportSession::count())->toBe(1)
        ->and(ImportBatch::count())->toBe(1)
        ->and($session->countOf('valid'))->toBe(2)
        ->and($session->countOf('nope'))->toBe(0)
        ->and($batch->model()->countOf('created'))->toBe(2);
});
