<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Actions\Rollback\RollbackChunkAction;
use RoundlyConsulting\Imports\Definition\ChangeDetection;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportRollbackStarted;
use RoundlyConsulting\Imports\Events\ImportRolledBack;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Exceptions\ImportUnauthorizedException;
use RoundlyConsulting\Imports\Exceptions\UndoWindowClosedException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Importers\AssetsImporter;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Invitation;
use Workbench\Database\Factories\AssetFactory;

test('undo_is_refused_after_the_window', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();

    CarbonImmutable::setTestNow(now()->addDays(7)->addMinute());

    expect($batch->canRollback())->toBeFalse()
        ->and(fn () => $batch->rollback())->toThrow(UndoWindowClosedException::class);
    CarbonImmutable::setTestNow();
});

test('undo_is_refused_while_committing', function (): void {
    Bus::fake();
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();

    expect(fn () => $batch->rollback())->toThrow(ImportStateException::class);
});

test('undo_needs_the_rollback_permission', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();

    expect(fn () => $batch->by(admin(team()))->rollback())->toThrow(ImportUnauthorizedException::class);
});

test('a_second_rollback_is_a_no_op', function (): void {
    Event::fake([ImportRolledBack::class, ImportRollbackStarted::class]);
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();

    $batch->rollback();
    $first = $batch->rollbackReport();
    $batch->rollback();

    expect($batch->rollbackReport())->toEqual($first)
        ->and($batch->session()->status())->toBe(SessionStatus::RolledBack)
        ->and($batch->model()->rollback_status)->toBe(RollbackStatus::RolledBack);
    Event::assertDispatchedTimes(ImportRolledBack::class, 1);
    Event::assertDispatchedTimes(ImportRollbackStarted::class, 1);
});

test('a_retried_rollback_deletes_nothing_twice', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();
    $ids = ImportedRecord::query()->orderBy('id')->pluck('id')->all();
    $batch->model()->forceFill(['rollback_status' => RollbackStatus::RollingBack])->save();

    app(RollbackChunkAction::class)->execute($batch->id(), $ids[0], $ids[2]);
    // A record created again with the same id would be deleted by a second pass — there is none.
    $redo = AssetFactory::new()->create(['team_id' => $team->id]);
    app(RollbackChunkAction::class)->execute($batch->id(), $ids[0], $ids[2]);

    expect(Asset::query()->pluck('id')->all())->toBe([$redo->id])
        ->and(ImportedRecord::query()->whereNotNull('rolled_back_at')->count())->toBe(3);
});

test('checksum_detects_a_changed_attribute', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    Asset::query()->where('asset_tag', 'AST-0001')->update(['status' => 'repair']);   // a quiet mass update

    $batch->rollback();

    expect(Asset::query()->pluck('asset_tag')->all())->toBe(['AST-0001'])
        ->and($batch->rollbackReport()?->keptChanged)->toBe(1)
        ->and($batch->rollbackReport()?->kept[0]->rollbackOutcome)->toBe(RollbackOutcome::KeptChanged);
});

test('a_touch_alone_is_not_a_change_under_checksum', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();

    CarbonImmutable::setTestNow(now()->addMinutes(5));
    Asset::query()->firstOrFail()->touch();
    CarbonImmutable::setTestNow();

    $batch->rollback();

    expect(Asset::query()->count())->toBe(0)->and($batch->rollbackReport()?->deleted)->toBe(2);
});

test('updated_at_detection_works_when_chosen', function (): void {
    AssetsImporter::$changeDetection = ChangeDetection::updatedAt();
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();

    CarbonImmutable::setTestNow(now()->addMinutes(5));
    Asset::query()->where('asset_tag', 'AST-0002')->firstOrFail()->touch();
    CarbonImmutable::setTestNow();

    $batch->rollback();
    AssetsImporter::$changeDetection = null;

    expect(Asset::query()->pluck('asset_tag')->all())->toBe(['AST-0002'])->and($batch->rollbackReport()?->keptChanged)->toBe(1);
});

test('a_missing_record_is_reported_missing', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();
    Asset::query()->where('asset_tag', 'AST-0001')->firstOrFail()->forceDelete();
    Asset::query()->where('asset_tag', 'AST-0002')->firstOrFail()->delete();      // soft-deleted

    $batch->rollback();
    $report = $batch->rollbackReport();

    expect($report?->missing)->toBe(2)->and($report?->deleted)->toBe(1)
        ->and(ImportedRecord::query()->where('rollback_reason', 'already_deleted')->count())->toBe(1);
});

test('a_hook_may_keep_a_record', function (): void {
    $team = team();
    $batch = Imports::for($team)->by(admin($team))
        ->upload('people', SpreadsheetFactory::csv(['Email'], [['a@example.com']]))->preview()->commit();
    Invitation::query()->update(['status' => 'accepted']);
    // Accepted, but the checksum is refreshed as if the change happened at import.
    ImportedRecord::query()->update(['checksum' => null]);

    $batch->rollback();

    expect($batch->rollbackReport()?->keptByHook)->toBe(1)->and(Invitation::query()->firstOrFail()->status)->toBe('accepted');
});

test('rollback_never_reverts_updates', function (): void {
    $team = team();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'name' => 'Old']);
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->mode(DuplicateMode::Update)->preview()->commit();

    $batch->rollback();

    expect(Asset::query()->pluck('name')->all())->toBe(['Laptop 1'])
        ->and($batch->rollbackReport()?->deleted)->toBe(1);
});

test('a_large_rollback_visits_every_created_record', function (): void {
    config()->set('imports.commit.chunk_size', 3);
    config()->set('imports.preview.sync_threshold', 2);
    config()->set('imports.queue.connection', 'database');
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(10)));
    $session->preview(false);
    $batch = $session->commit();
    drainQueue();

    $batch->rollback();
    expect($batch->model()->refresh()->rollback_batch_id)->not->toBeNull()
        ->and($batch->rollbackReport())->toBeNull()
        ->and($batch->progress()->phase)->toBe('rollback');

    drainQueue();

    expect(Asset::query()->count())->toBe(0)
        ->and($batch->rollbackReport()?->deleted)->toBe(10)
        ->and($batch->progress()->percent)->toBe(100);
});

test('a_record_that_cannot_be_deleted_is_reported_and_the_rest_roll_back', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();
    Asset::deleting(static function (Asset $asset): void {
        if ($asset->asset_tag === 'AST-0002') {
            throw new RuntimeException('A host foreign key still points at it.');
        }
    });

    $batch->rollback();
    Asset::flushEventListeners();

    expect(Asset::query()->pluck('asset_tag')->all())->toBe(['AST-0002'])
        ->and($batch->rollbackReport()?->failed)->toBe(1)
        ->and($batch->rollbackReport()?->deleted)->toBe(2)
        ->and(ImportedRecord::query()->where('rollback_outcome', RollbackOutcome::Failed)->value('rollback_reason'))->toContain('foreign key');
});

test('a_failed_rollback_can_be_retried', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    $fail = true;
    Asset::deleting(static function () use (&$fail): void {
        if ($fail) {
            throw new QueryException('testing', 'delete', [], new PDOException('server has gone away'));
        }
    });

    expect(fn () => $batch->rollback())->toThrow(QueryException::class)
        ->and($batch->model()->refresh()->rollback_status)->toBe(RollbackStatus::RollbackFailed)
        ->and(ImportSession::query()->firstOrFail()->status)->toBe(SessionStatus::RollbackFailed);

    $fail = false;
    $batch->rollback();
    Asset::flushEventListeners();

    expect(Asset::query()->count())->toBe(0)->and($batch->rollbackReport()?->deleted)->toBe(2);
});
