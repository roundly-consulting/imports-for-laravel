<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB as Database;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Actions\Commit\FailImportAction;
use RoundlyConsulting\Imports\Actions\Commit\ProcessChunkAction;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportChunkProcessed;
use RoundlyConsulting\Imports\Events\ImportCompleted;
use RoundlyConsulting\Imports\Events\ImportFailed;
use RoundlyConsulting\Imports\Events\ImportStarted;
use RoundlyConsulting\Imports\Exceptions\ImportAlreadyRunningException;
use RoundlyConsulting\Imports\Exceptions\ImportRowException;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Exceptions\ImportUnauthorizedException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use RoundlyConsulting\Imports\Support\Digests;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\ContextOwnerScope;
use RoundlyConsulting\Imports\Tests\Support\ContextTeams;
use RoundlyConsulting\Imports\Tests\Support\TeamAssetsImporter;
use Workbench\App\Hooks;
use Workbench\App\Jobs\SendInvitation;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Invitation;
use Workbench\Database\Factories\AssetFactory;
use Workbench\Database\Factories\InvitationFactory;
use Workbench\Database\Factories\VendorFactory;

test('commit_refuses_a_stale_preview', function (): void {
    $team = team();
    $user = admin($team);
    $session = uploadAssets($team, $user, assetsCsv(assetRows(2)))->preview();

    // Another request changed the mode meanwhile: this handle's preview version is stale.
    $stale = Imports::session(ImportSession::query()->findOrFail($session->id()))->by($user);
    $session->mode(DuplicateMode::CreateNew);

    expect(fn () => $stale->commit())->toThrow(ImportStateException::class, 'out of date')
        ->and(Asset::query()->count())->toBe(0);
});

test('commit_refuses_an_expired_session', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    CarbonImmutable::setTestNow(now()->addDays(3));

    expect(fn () => $session->commit())->toThrow(ImportStateException::class, 'expired');
    CarbonImmutable::setTestNow();
});

test('commit_refuses_without_authorization', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    $viewer = admin($team);
    $viewer->forceFill(['is_admin' => false])->save();

    expect(fn () => $session->by($viewer)->commit())->toThrow(ImportUnauthorizedException::class)
        ->and(fn () => uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->commit())->toThrow(ImportStateException::class);
});

test('a_reference_deleted_after_the_preview_fails_the_row_at_commit', function (): void {
    $team = team();
    $vendor = VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Lenovo']);
    $rows = assetRows(2);
    $rows[0][7] = 'Lenovo';
    $session = uploadAssets($team, admin($team), assetsCsv($rows))->preview();
    $vendor->delete();

    $batch = $session->commit();
    $failed = $session->rows(RowFilter::invalid())->paginate()->items();

    expect($batch->summary()->created)->toBe(1)
        ->and($batch->summary()->failed)->toBe(1)
        ->and($batch->summary()->reclassified)->toBe(1)
        ->and($failed[0]->rowNumber)->toBe(2)
        ->and($failed[0]->errors[0]->code)->toBe('unknown_reference');
});

test('a_record_created_after_the_preview_is_matched_at_commit', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview();
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0002', 'name' => 'Meanwhile']);

    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(1)
        ->and($batch->summary()->skipped)->toBe(1)
        ->and($batch->summary()->reclassified)->toBe(1)
        ->and(Asset::query()->where('asset_tag', 'AST-0002')->count())->toBe(1);
});

test('a_unique_violation_from_a_concurrent_writer_fails_only_its_row', function (): void {
    $team = team();
    $rows = assetRows(2);
    $session = uploadAssets($team, admin($team), assetsCsv($rows))->mode(DuplicateMode::CreateNew)->preview();

    // Created meanwhile under another tag: the host's (team, serial) unique index refuses row 3.
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'ELSE', 'serial_number' => 'SN-00002']);
    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(1)
        ->and($batch->summary()->failed)->toBe(1)
        ->and($session->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->code)->toBe('duplicate_created_meanwhile');
});

test('a_record_outside_the_owner_fails_its_row_and_is_rolled_back', function (): void {
    $team = team();
    $other = team();
    Hooks::$onCreate = static function (int $row) use ($other): void {
        if ($row === 3) {
            // The callback writes into the other team (the hook runs before Asset::create).
            Asset::query()->create(['team_id' => $other->id, 'name' => 'leak', 'asset_tag' => 'LEAK']);
        }
    };

    // Point the create at the other team for row 3.
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview();
    $batch = $session->commit();

    // The leaked write happened inside row 3's savepoint, before the importer's own create;
    // the row itself is fine. Now prove the ownership check: an importer returning another
    // team's record is refused and its savepoint rolled back.
    Hooks::$onCreate = null;
    expect($batch->summary()->created)->toBe(3);

    Imports::register(OutsideOwnerImporter::class);
    $outside = Imports::for($team)->by(admin($team))->upload('outside', SpreadsheetFactory::csv(['Name'], [['One'], ['Two']]))->preview()->commit();

    expect($outside->summary()->failed)->toBe(2)
        ->and($outside->summary()->created)->toBe(0)
        ->and(Asset::query()->where('name', 'like', 'outside-%')->count())->toBe(0)
        ->and($outside->session()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->code)->toBe('callback_failed');
});

test('an_unsaved_model_fails_the_row', function (): void {
    Imports::register(UnsavedImporter::class);
    $team = team();

    $batch = Imports::for($team)->by(admin($team))->upload('unsaved', SpreadsheetFactory::csv(['Name'], [['One']]))->preview()->commit();
    $error = $batch->session()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0];

    expect($batch->summary()->failed)->toBe(1)->and($error->message)->toBe('The record was not saved.');
});

test('a_write_on_another_connection_is_refused', function (): void {
    config()->set('database.connections.other', config('database.connections.testing'));
    Imports::register(OtherConnectionImporter::class);
    $team = team();

    $batch = Imports::for($team)->by(admin($team))->upload('other-connection', SpreadsheetFactory::csv(['Name'], [['One']]))->preview()->commit();

    expect($batch->summary()->failed)->toBe(1)
        ->and($batch->session()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->message)->toBe('The record was saved on another database connection.');
});

test('an_import_row_exception_message_reaches_the_user', function (): void {
    Hooks::$onCreate = static function (int $row): void {
        if ($row === 2) {
            throw ImportRowException::because('Asset tag reserved by finance.');
        }
    };
    $team = team();

    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();

    expect($batch->summary()->failed)->toBe(1)->and($batch->summary()->created)->toBe(1)
        ->and($batch->session()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0]->message)->toBe('Asset tag reserved by finance.');
});

test('other_exceptions_are_reported_and_shown_generically', function (): void {
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });
    Hooks::$onCreate = static function (int $row): void {
        if ($row === 2) {
            throw new RuntimeException('SMTP password is hunter2');
        }
    };
    $team = team();

    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    $error = $batch->session()->rows(RowFilter::invalid())->paginate()->items()[0]->errors[0];

    expect($error->message)->toBe('The row could not be imported.')->not->toContain('hunter2')
        ->and($reported)->toContain('SMTP password is hunter2');
});

test('row_errors_fail_the_row_and_infrastructure_errors_retry_the_chunk', function (): void {
    config()->set('imports.queue.connection', 'database');
    $team = team();
    $attempts = 0;
    Hooks::$onCreate = static function (int $row) use (&$attempts): void {
        if ($row === 2) {
            $attempts++;

            if ($attempts === 1) {
                throw new QueryException('testing', 'insert', [], new PDOException('Deadlock found when trying to get lock'));
            }

            throw new LogicException('row-level failure');
        }
    };

    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();
    drainQueue();

    expect($attempts)->toBe(2)
        ->and($batch->status())->toBe(BatchStatus::Completed)
        ->and($batch->summary()->created)->toBe(2)
        ->and($batch->summary()->failed)->toBe(1);
});

test('a_chunk_delivered_twice_writes_once', function (): void {
    config()->set('imports.commit.chunk_size', 2);
    Bus::fake();
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(4)))->preview()->commit();

    app(ProcessChunkAction::class)->execute($batch->id(), 0, true);
    app(ProcessChunkAction::class)->execute($batch->id(), 0, true);
    app(ProcessChunkAction::class)->execute($batch->id(), 1);
    app(ProcessChunkAction::class)->execute($batch->id(), 1);
    // A chunk out of order (its predecessor never ran) is refused too.
    app(ProcessChunkAction::class)->execute($batch->id(), 5);

    expect(Asset::query()->count())->toBe(4)
        ->and(ImportedRecord::query()->count())->toBe(4)
        ->and($batch->model()->refresh()->chunks_completed)->toBe(2);
});

test('invitation_mail_is_sent_once_after_a_retried_chunk', function (): void {
    config()->set('queue.default', 'database');
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.queue.queue', 'imports');
    $team = team();
    $faults = 1;
    Hooks::$onCreate = static function (int $row) use (&$faults): void {
        if ($row === 3 && $faults > 0) {
            $faults--;

            throw new QueryException('testing', 'insert', [], new PDOException('SQLSTATE[HY000]: server has gone away'));
        }
    };

    Imports::for($team)->by(admin($team))->upload('people', SpreadsheetFactory::csv(['Email'], [['a@example.com'], ['b@example.com'], ['c@example.com']]))->preview()->commit();

    drainQueue('imports');

    // The first attempt created rows 1–2 and queued their mails after commit; its transaction
    // rolled back, so those after-commit jobs were discarded — one mail per invitation.
    $mails = Database::table('jobs')->where('queue', 'mail')->get()->map(fn ($job) => unserialize(json_decode($job->payload, true)['data']['command'])->invitationId)->all();

    expect(Invitation::query()->count())->toBe(3)
        ->and($mails)->toHaveCount(3)
        ->and(array_unique($mails))->toHaveCount(3)
        ->and(SendInvitation::class)->toBeString();
});

test('two_concurrent_commits_for_one_owner_and_importer_admit_one', function (): void {
    Bus::fake();
    $team = team();
    $user = admin($team);
    $first = uploadAssets($team, $user, assetsCsv(assetRows(2)))->preview();
    $second = uploadAssets($team, $user, assetsCsv(assetRows(2, 10)))->preview();

    $first->commit();

    try {
        $second->commit();
        $this->fail('Expected the lock to refuse the second commit.');
    } catch (ImportAlreadyRunningException $exception) {
        expect($exception->runningSessionId)->toBe((string) $first->id());
    }

    expect($second->status())->toBe(SessionStatus::Previewed)
        ->and(Imports::for($team)->by($user)->running('assets')?->id())->toBe($first->id());
});

test('different_owners_import_in_parallel', function (): void {
    Bus::fake();
    $a = team();
    $b = team();

    uploadAssets($a, admin($a), assetsCsv(assetRows(1)))->preview()->commit();
    uploadAssets($b, admin($b), assetsCsv(assetRows(1)))->preview()->commit();

    expect(ImportSession::query()->where('status', SessionStatus::Committing)->count())->toBe(2)
        ->and(Imports::for($a)->by(admin($a))->running('people'))->toBeNull();
});

test('a_double_commit_starts_one_batch', function (): void {
    Bus::fake();
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    $again = Imports::session(ImportSession::query()->findOrFail($session->id()))->by(admin($team));

    $session->commit();

    expect(fn () => $again->commit())->toThrow(ImportStateException::class)
        ->and(ImportBatch::query()->count())->toBe(1);
});

test('a_rollback_and_a_commit_cannot_overlap', function (): void {
    $team = team();
    $user = admin($team);
    $done = uploadAssets($team, $user, assetsCsv(assetRows(1)))->preview()->commit();
    $next = uploadAssets($team, $user, assetsCsv(assetRows(1, 5)))->preview();

    // Hold the lock as a running rollback would.
    ImportSession::query()->whereKey($done->model()->session_id)->update(['status' => SessionStatus::RollingBack, 'lock_key' => Digests::lockKey('assets', $team)]);

    expect(fn () => $next->commit())->toThrow(ImportAlreadyRunningException::class);
});

test('a_batch_whose_actor_lost_access_fails_unauthorized', function (): void {
    config()->set('imports.queue.connection', 'database');
    Event::fake([ImportFailed::class]);
    $team = team();
    $user = admin($team);
    $batch = uploadAssets($team, $user, assetsCsv(assetRows(3)))->preview()->commit();

    $user->forceFill(['is_admin' => false])->save();
    drainQueue();

    expect($batch->model()->refresh()->status)->toBe(BatchStatus::Failed)
        ->and($batch->model()->failure['reason'] ?? null)->toBe('unauthorized')
        ->and(Asset::query()->count())->toBe(0);
    Event::assertDispatchedTimes(ImportFailed::class, 1);
});

test('a_cancelled_import_keeps_written_rows_and_is_undoable', function (): void {
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.commit.chunk_size', 2);
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(6)))->preview()->commit();

    // Run the first chunk, then cancel: the rest of the chain returns early.
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--memory' => 4096]);
    $batch->cancel();
    drainQueue();

    expect($batch->status())->toBe(BatchStatus::Cancelled)
        ->and($batch->session()->status())->toBe(SessionStatus::Cancelled)
        ->and(Asset::query()->count())->toBe(2)
        ->and($batch->canRollback())->toBeTrue();

    $batch->rollback();
    expect(Asset::query()->count())->toBe(0);
});

test('cancelling_a_finished_or_missing_laravel_batch_closes_it_directly', function (): void {
    Bus::fake();
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    $batch->model()->forceFill(['laravel_batch_id' => null])->save();

    $batch->cancel();

    expect($batch->status())->toBe(BatchStatus::Cancelled)
        ->and(fn () => $batch->cancel())->toThrow(ImportStateException::class);
});

test('a_failed_import_resumes_from_the_next_chunk', function (): void {
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.commit.chunk_size', 2);
    config()->set('imports.commit.tries', 1);
    $team = team();
    $fail = true;
    Hooks::$onCreate = static function (int $row) use (&$fail): void {
        if ($row === 4 && $fail) {
            throw new QueryException('testing', 'insert', [], new PDOException('server has gone away'));
        }
    };
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(6)))->preview()->commit();
    drainQueue();

    expect($batch->status())->toBe(BatchStatus::Failed)
        ->and($batch->model()->chunks_completed)->toBe(1)
        ->and(Asset::query()->count())->toBe(2);

    $fail = false;
    $batch->resume();
    drainQueue();

    expect($batch->status())->toBe(BatchStatus::Completed)
        ->and(Asset::query()->count())->toBe(6)
        ->and($batch->summary()->created)->toBe(6)
        ->and(fn () => $batch->resume())->toThrow(ImportStateException::class);
});

test('a_pruned_file_cannot_be_resumed', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview()->commit();
    $batch->model()->forceFill(['status' => BatchStatus::Failed])->save();
    ImportSession::query()->whereKey($batch->model()->session_id)->update(['status' => SessionStatus::Failed]);
    Storage::disk('imports')->delete($batch->session()->model()->file_path);

    expect(fn () => $batch->resume())->toThrow(ImportStateException::class, 'no longer available');
});

test('a_failed_chunk_fires_import_failed_once', function (): void {
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.commit.chunk_size', 1);
    config()->set('imports.commit.tries', 1);
    Event::fake([ImportFailed::class, ImportCompleted::class]);
    Hooks::$onCreate = static fn (int $row): never => throw new QueryException('testing', 'insert', [], new PDOException('server has gone away'));
    $team = team();

    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();
    drainQueue();

    expect($batch->status())->toBe(BatchStatus::Failed)->and($batch->model()->undo_until)->not->toBeNull();
    Event::assertDispatchedTimes(ImportFailed::class, 1);
    Event::assertNotDispatched(ImportCompleted::class);

    // A late duplicate of the callback changes nothing.
    app(FailImportAction::class)->execute($batch->id(), 'chunk_failed');
    Event::assertDispatchedTimes(ImportFailed::class, 1);
});

test('events_carry_actor_owner_counts_and_file_reference', function (): void {
    Event::fake([ImportStarted::class, ImportChunkProcessed::class, ImportCompleted::class]);
    $team = team();
    $uploader = admin($team);
    $committer = admin($team);
    $session = uploadAssets($team, $uploader, assetsCsv(assetRows(3)))->preview();

    $session->by($committer)->commit();

    Event::assertDispatched(ImportCompleted::class, function (ImportCompleted $event) use ($team, $committer): bool {
        return $event->audit->owner->is($team)
            && $event->audit->actor?->is($committer)
            && $event->audit->importer === 'assets'
            && $event->audit->counts->valid === 3
            && $event->audit->file->originalName === 'import.csv'
            && $event->audit->file->sha256 !== null
            && $event->summary->created === 3;
    });
    Event::assertDispatched(ImportStarted::class, fn (ImportStarted $event): bool => $event->audit->batchId !== null);
    Event::assertDispatched(ImportChunkProcessed::class, fn (ImportChunkProcessed $event): bool => $event->chunk === 0 && $event->rows === 3);
});

test('import_event_listeners_run_in_the_owner_context', function (): void {
    app()->bind(OwnerScope::class, ContextOwnerScope::class);
    config()->set('imports.queue.connection', 'database');
    $team = team();
    $seen = [];
    Event::listen(ImportCompleted::class, function () use (&$seen): void {
        $seen[] = ContextTeams::current();
    });

    ContextTeams::set(team());   // the dispatcher's own team
    uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    drainQueue();
    ContextTeams::set(null);

    expect($seen)->toBe([$team->id]);
});

test('a_commit_job_runs_as_the_import_owner_not_the_dispatchers_team', function (): void {
    app()->bind(OwnerScope::class, ContextOwnerScope::class);
    config()->set('imports.queue.connection', 'database');
    Imports::register(TeamAssetsImporter::class);
    $owner = team();
    $dispatcher = team();

    ContextTeams::set($dispatcher);
    Imports::for($owner)->by(admin($owner))->upload('team-assets', SpreadsheetFactory::csv(['Asset tag', 'Name'], [['T-1', 'One']]))->preview()->commit();
    drainQueue();
    ContextTeams::set(null);

    expect(Asset::query()->pluck('team_id')->all())->toBe([$owner->id]);
});

test('a_create_callback_returning_an_existing_record_is_never_rolled_back', function (): void {
    $team = team();
    $pending = InvitationFactory::new()->create(['team_id' => $team->id, 'email' => 'ada@example.com', 'status' => 'pending']);

    // The importer has no way to know: its natural key ignores status, CreateNew bypasses the match.
    $batch = Imports::for($team)->by(admin($team))->upload('people', SpreadsheetFactory::csv(['Email'], [['ada@example.com'], ['new@example.com']]))
        ->mode(DuplicateMode::CreateNew)->preview()->commit();

    expect($batch->summary()->linked)->toBe(1)->and($batch->summary()->created)->toBe(1)
        ->and($batch->records(RecordAction::Linked)->count())->toBe(1);

    $batch->rollback();

    expect($pending->refresh()->status)->toBe('pending')
        ->and($batch->rollbackReport()?->linked)->toBe(1)
        ->and($batch->rollbackReport()?->kept)->toHaveCount(1);
});

test('the_chain_never_exceeds_max_chunks', function (): void {
    config()->set('imports.commit.chunk_size', 1);
    config()->set('imports.commit.max_chunks', 4);
    Bus::fake();
    $team = team();

    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(10)))->preview()->commit();

    expect($batch->model()->total_chunks)->toBe(4)
        ->and($batch->model()->chunk_size)->toBe(3);
    Bus::assertBatched(fn ($pending): bool => count($pending->jobs->first()) === 4);
});

test('commit_chunks_seek_instead_of_rereading_the_workbook', function (): void {
    config()->set('imports.commit.chunk_size', 2);
    $team = team();
    $session = uploadAssets($team, admin($team), SpreadsheetFactory::xlsx(assetHeaders(), assetRows(5)))->preview();
    $model = $session->model()->refresh();

    expect($model->working_copy_path)->not->toBeNull()
        ->and(Storage::disk('imports')->exists((string) $model->working_copy_path))->toBeTrue()
        ->and(array_column($model->chunk_offsets ?? [], 1))->toBe([2, 4, 6]);

    // The workbook is gone; commit chunks read the working copy at their offsets only.
    Storage::disk('imports')->delete($model->file_path);
    $batch = $session->commit();

    expect($batch->summary()->created)->toBe(5)->and(Asset::query()->orderBy('id')->pluck('asset_tag')->all())->toBe(['AST-0001', 'AST-0002', 'AST-0003', 'AST-0004', 'AST-0005']);
});

test('with_the_queue_disabled_everything_runs_inline', function (): void {
    config()->set('imports.queue.enabled', 'off');
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.preview.sync_threshold', 1);
    $team = team();

    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(5)))->preview();
    expect($session->status())->toBe(SessionStatus::Previewed);

    $batch = $session->commit();
    expect($batch->status())->toBe(BatchStatus::Completed)->and(Asset::query()->count())->toBe(5)
        ->and(Database::table('jobs')->count())->toBe(0);

    $batch->rollback();
    expect(Asset::query()->count())->toBe(0);
});

final class OutsideOwnerImporter extends Importer
{
    public function key(): string
    {
        return 'outside';
    }

    public function label(): string
    {
        return 'Outside';
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
        return [Field::text('name')->required()];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return Asset::query()->create(['team_id' => $context->owner->getKey() + 1000, 'name' => 'outside-'.$row->get('name')]);
    }
}

final class UnsavedImporter extends Importer
{
    public function key(): string
    {
        return 'unsaved';
    }

    public function label(): string
    {
        return 'Unsaved';
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
        return [Field::text('name')];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return new Asset(['team_id' => $context->owner->getKey(), 'name' => 'never saved']);
    }
}

final class OtherConnectionImporter extends Importer
{
    public function key(): string
    {
        return 'other-connection';
    }

    public function label(): string
    {
        return 'Other connection';
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
        return [Field::text('name')];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        $asset = Asset::query()->create(['team_id' => $context->owner->getKey(), 'name' => 'x']);
        $asset->setConnection('other');

        return $asset;
    }
}
