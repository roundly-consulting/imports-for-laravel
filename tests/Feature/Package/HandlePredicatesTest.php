<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Facades\Imports;

test('the_session_predicates_follow_the_status_machine', function (SessionStatus $status, bool $validating, bool $previewed, bool $committed): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)));
    $session->model()->forceFill(['status' => $status])->save();

    expect($session->isValidating())->toBe($validating)
        ->and($session->isPreviewed())->toBe($previewed)
        ->and($session->isCommitted())->toBe($committed);
})->with([
    'uploaded' => [SessionStatus::Uploaded, false, false, false],
    'needs mapping' => [SessionStatus::NeedsMapping, false, false, false],
    'mapped' => [SessionStatus::Mapped, false, false, false],
    'validating' => [SessionStatus::Validating, true, false, false],
    'previewed' => [SessionStatus::Previewed, false, true, false],
    'preview failed' => [SessionStatus::PreviewFailed, false, false, false],
    'committing' => [SessionStatus::Committing, false, false, true],
    'completed' => [SessionStatus::Completed, false, false, true],
    'failed' => [SessionStatus::Failed, false, false, true],
    'cancelled' => [SessionStatus::Cancelled, false, false, true],
    'rolling back' => [SessionStatus::RollingBack, false, false, true],
    'rolled back' => [SessionStatus::RolledBack, false, false, true],
    'rollback failed' => [SessionStatus::RollbackFailed, false, false, true],
    'discarded' => [SessionStatus::Discarded, false, false, false],
    'expired' => [SessionStatus::Expired, false, false, false],
]);

test('the_batch_predicates_follow_the_batch_status', function (BatchStatus $status, ?RollbackStatus $rollback, bool $running, bool $finished, bool $rolledBack): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    $batch->model()->forceFill(['status' => $status, 'rollback_status' => $rollback])->save();

    expect($batch->isRunning())->toBe($running)
        ->and($batch->isFinished())->toBe($finished)
        ->and($batch->isRolledBack())->toBe($rolledBack);
})->with([
    'pending' => [BatchStatus::Pending, null, true, false, false],
    'running' => [BatchStatus::Running, null, true, false, false],
    'completed' => [BatchStatus::Completed, null, false, true, false],
    'failed' => [BatchStatus::Failed, null, false, true, false],
    'cancelled' => [BatchStatus::Cancelled, null, false, true, false],
    'rolling back' => [BatchStatus::Completed, RollbackStatus::RollingBack, false, true, false],
    'rolled back' => [BatchStatus::Completed, RollbackStatus::RolledBack, false, true, true],
]);

test('the_readme_flow_can_branch_on_the_preview_state', function (): void {
    config()->set(['imports.queue.connection' => 'database', 'imports.preview.sync_threshold' => 1]);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview();

    expect($session->isValidating())->toBeTrue()->and($session->isPreviewed())->toBeFalse();

    drainQueue();

    expect($session->isPreviewed())->toBeTrue();
});

test('by_refuses_an_actor_that_is_not_a_model', function (Closure $by): void {
    $team = team();
    $generic = new GenericUser(['id' => 1]);

    expect(fn () => $by($team, $generic))->toThrow(LogicException::class, 'The actor must be an Eloquent model');
})->with([
    'owner imports' => [fn ($team, $user) => Imports::for($team)->by($user)],
    'session' => [fn ($team, $user) => uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->by($user)],
    'batch' => [fn ($team, $user) => uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit()->by($user)],
]);
