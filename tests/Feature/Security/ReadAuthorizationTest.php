<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportNotFoundException;
use RoundlyConsulting\Imports\Exceptions\ImportUnauthorizedException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\BatchHandle;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Hooks;
use Workbench\Database\Factories\UserFactory;

test('every_read_on_a_session_or_batch_asks_authorize_view', function (Closure $read): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    $member = UserFactory::new()->create(['team_id' => $team->id, 'is_admin' => false]);
    $owned = Imports::for($team)->by($member);
    $session = $owned->session($batch->session()->id());

    expect(fn () => $read($session, $owned->batch($batch->id())))->toThrow(ImportUnauthorizedException::class);
})->with([
    'session status' => [fn (SessionHandle $s, BatchHandle $b) => $s->status()],
    'session sheets' => [fn (SessionHandle $s, BatchHandle $b) => $s->sheets()],
    'session headers' => [fn (SessionHandle $s, BatchHandle $b) => $s->headers()],
    'session counts' => [fn (SessionHandle $s, BatchHandle $b) => $s->counts()],
    'session progress' => [fn (SessionHandle $s, BatchHandle $b) => $s->progress()],
    'session batch' => [fn (SessionHandle $s, BatchHandle $b) => $s->batch()],
    'session preview data' => [fn (SessionHandle $s, BatchHandle $b) => $s->previewData()],
    'session rows' => [fn (SessionHandle $s, BatchHandle $b) => $s->rows()],
    'session suggestion' => [fn (SessionHandle $s, BatchHandle $b) => $s->suggestMapping()],
    'batch status' => [fn (SessionHandle $s, BatchHandle $b) => $b->status()],
    'batch progress' => [fn (SessionHandle $s, BatchHandle $b) => $b->progress()],
    'batch summary' => [fn (SessionHandle $s, BatchHandle $b) => $b->summary()],
    'batch records' => [fn (SessionHandle $s, BatchHandle $b) => $b->records()],
    'batch can rollback' => [fn (SessionHandle $s, BatchHandle $b) => $b->canRollback()],
    'batch rollback report' => [fn (SessionHandle $s, BatchHandle $b) => $b->rollbackReport()],
    'batch session' => [fn (SessionHandle $s, BatchHandle $b) => $b->session()],
    'owner sessions of one importer' => [fn (SessionHandle $s, BatchHandle $b) => Imports::for(team())->sessions('assets')],
    'owner running' => [fn (SessionHandle $s, BatchHandle $b) => Imports::for(team())->running('assets')],
]);

test('the_trusted_path_lets_authorize_decide_for_a_null_actor', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1)));

    expect(fn () => Imports::session($session->id())->status())->toThrow(ImportUnauthorizedException::class);

    Hooks::$authorize = static fn (string $operation): bool => $operation === 'view';

    expect(Imports::session($session->id())->status())->toBe(SessionStatus::Mapped);
});

test('a_polling_handle_asks_authorize_once_per_operation', function (): void {
    $team = team();
    $user = admin($team);
    $batch = uploadAssets($team, $user, assetsCsv(assetRows(2)))->preview()->commit();
    $session = Imports::for($team)->by($user)->session($batch->session()->id());
    $polled = Imports::for($team)->by($user)->batch($batch->id());
    Hooks::$authorized = [];

    foreach (range(1, 5) as $poll) {
        $session->status();
        $session->counts();
        $session->progress();
        $polled->progress();
        $polled->summary();
    }

    expect(array_count_values(Hooks::$authorized))->toBe(['view' => 2]);
});

test('sessions_lists_only_importers_the_actor_may_view', function (): void {
    $team = team();
    $user = admin($team);
    uploadAssets($team, $user, assetsCsv(assetRows(1)));
    Imports::for($team)->by($user)->upload('people', SpreadsheetFactory::csv(['Email', 'Role'], [['a@example.com', 'member']]));

    expect(Imports::for($team)->by($user)->sessions()->pluck('importer')->sort()->values()->all())->toBe(['assets', 'people']);

    Hooks::$authorize = static fn (string $operation): bool => $operation !== 'view';

    expect(Imports::for($team)->by($user)->sessions()->pluck('importer')->all())->toBe(['people'])
        ->and(fn () => Imports::for($team)->by($user)->sessions('assets'))->toThrow(ImportUnauthorizedException::class);
});

test('a_missing_batch_is_reported_as_a_batch', function (): void {
    $team = team();

    expect(fn () => Imports::batch('999999'))->toThrow(ImportNotFoundException::class, 'The import run was not found.')
        ->and(fn () => Imports::for($team)->batch('999999'))->toThrow(ImportNotFoundException::class, 'The import run was not found.')
        ->and(fn () => Imports::for($team)->session('999999'))->toThrow(ImportNotFoundException::class, 'The import was not found.');
});
