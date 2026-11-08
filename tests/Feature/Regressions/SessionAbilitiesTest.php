<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Http\Resources\ImportSessionResource;
use RoundlyConsulting\Imports\Models\ImportSession;

// Dual review 2026-11-08, O-9 (a = F-4): the session resource's `can` flags had their own rules —
// a failed undo could not be retried from the UI, a closed undo window or an expired preview
// still offered the button.

/**
 * @return array{commit: bool, rollback: bool}
 */
function canOf(int|string $sessionId): array
{
    $session = ImportSession::query()->findOrFail($sessionId);

    /** @var array{commit: bool, rollback: bool} $can */
    $can = (new ImportSessionResource($session))->resolve(Request::create('/'))['can'];

    return $can;
}

it('O-9: can.rollback follows the batch rules', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    $batch = $session->commit();

    expect(canOf($session->id()))->toBe(['commit' => false, 'rollback' => true])
        ->and($batch->canRollback())->toBeTrue();

    // (a) a failed undo can be started again
    $batch->model()->forceFill(['rollback_status' => RollbackStatus::RollbackFailed])->save();
    ImportSession::query()->whereKey($session->id())->update(['status' => SessionStatus::RollbackFailed->value]);
    expect(canOf($session->id())['rollback'])->toBeTrue()->and($batch->canRollback())->toBeTrue();

    // (b) a closed undo window offers nothing
    $batch->model()->forceFill(['rollback_status' => null, 'undo_until' => now()->subMinute()])->save();
    ImportSession::query()->whereKey($session->id())->update(['status' => SessionStatus::Completed->value]);
    expect(canOf($session->id())['rollback'])->toBeFalse()->and($batch->canRollback())->toBeFalse();
});

it('O-9: can.commit is false for an expired preview', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();

    expect(canOf($session->id()))->toBe(['commit' => true, 'rollback' => false]);

    ImportSession::query()->whereKey($session->id())->update(['expires_at' => now()->subMinute()]);
    expect(canOf($session->id())['commit'])->toBeFalse();
});
