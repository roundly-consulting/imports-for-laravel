<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportAlreadyRunningException;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\ImportLock;
use RoundlyConsulting\Imports\Tests\Support\Racer;

/**
 * The lock under real concurrency: two processes, two sessions of one owner and importer, the
 * first holding its lock in an open transaction while the second tries — it must wait on the
 * unique index and then be refused, never both admitted.
 */
test('two_concurrent_commits_for_one_owner_and_importer_admit_one (racer)', function (): void {
    Bus::fake();
    $team = team();
    $user = admin($team);
    $first = uploadAssets($team, $user, assetsCsv(assetRows(1)))->preview()->model();
    $second = uploadAssets($team, $user, assetsCsv(assetRows(1, 5)))->preview()->model();
    $secondId = $second->getKey();

    DB::beginTransaction();
    ImportLock::acquire($first, [SessionStatus::Previewed], SessionStatus::Committing, $first->preview_version);

    $racer = (new Racer)->run(static function () use ($secondId): string {
        $session = ImportSession::query()->findOrFail($secondId);

        try {
            ImportLock::acquire($session, [SessionStatus::Previewed], SessionStatus::Committing, $session->preview_version);

            return 'admitted';
        } catch (ImportAlreadyRunningException) {
            return 'refused';
        }
    })->waitUntilBlockedOrDone();

    DB::commit();

    expect($racer->outcome())->toBe('refused')
        ->and(ImportSession::query()->where('status', SessionStatus::Committing)->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs PostgreSQL or MySQL and pcntl');

test('a_lock_conflict_inside_a_host_transaction_leaves_it_usable', function (): void {
    Bus::fake();
    $team = team();
    $user = admin($team);
    uploadAssets($team, $user, assetsCsv(assetRows(1)))->preview()->commit();
    $second = uploadAssets($team, $user, assetsCsv(assetRows(1, 5)))->preview();

    DB::transaction(static function () use ($second): void {
        expect(fn () => $second->commit())->toThrow(ImportAlreadyRunningException::class);

        // On PostgreSQL a failed statement aborts the whole transaction — unless it ran in its
        // own savepoint, which the lock does.
        expect(ImportSession::query()->count())->toBe(2);
    });
});
