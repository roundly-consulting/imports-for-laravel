<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
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
