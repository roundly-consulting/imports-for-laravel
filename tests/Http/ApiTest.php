<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB as Database;
use RoundlyConsulting\Imports\DataTransferObjects\RecordReference;
use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Http\Resources\ImportActorResource;
use RoundlyConsulting\Imports\Http\Resources\ImportOwnerResource;
use RoundlyConsulting\Imports\Http\Resources\ImportRecordResource;
use RoundlyConsulting\Imports\Http\Resources\ImportSessionResource;
use RoundlyConsulting\Imports\Http\Resources\References;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;
use Workbench\Database\Factories\AssetFactory;

final class FixtureOwnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'name' => $this->resource->name, 'custom' => true];
    }
}

test('the_full_flow_runs_through_the_api', function (): void {
    $team = team();
    $user = admin($team);
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'name' => 'Old']);
    $rows = assetRows(4);
    $rows[2][2] = '';

    $this->actingAs($user);
    expect($this->getJson('/api/imports/importers')->assertOk()->json('data.0.key'))->toBe('assets');

    $upload = $this->post('/api/imports/importers/assets/sessions', ['file' => assetsCsv($rows), 'mode' => 'update'], ['Accept' => 'application/json'])->assertCreated();
    $id = $upload->json('data.id');

    expect($upload->json('data.status'))->toBe('mapped')
        ->and($upload->json('data.mode'))->toBe('update')
        ->and($upload->json('data.file'))->toBe(['name' => 'import.csv', 'size' => $upload->json('data.file.size')]);

    $this->getJson("/api/imports/sessions/{$id}/mapping")->assertOk()->assertJsonPath('data.complete', true)->assertJsonPath('data.columns.0.field', 'asset_tag');
    $this->putJson("/api/imports/sessions/{$id}/mapping", ['mapping' => ['Asset tag' => 'asset_tag', 'Name' => 'name', '4' => 'purchased_on']])->assertOk()
        ->assertJsonPath('data.mapping.2', ['column' => 4, 'field' => 'purchased_on']);
    $this->putJson("/api/imports/sessions/{$id}/mapping", ['mapping' => ['Name' => 'nope']])->assertUnprocessable()->assertJsonValidationErrors(['fields.nope', 'fields.name']);
    $this->postJson("/api/imports/sessions/{$id}/mapping/save", ['name' => 'API'])->assertOk();
    $this->getJson('/api/imports/importers/assets/mappings')->assertOk()->assertJsonPath('data.0.name', 'API');
    $this->putJson("/api/imports/sessions/{$id}/mode", ['mode' => 'update'])->assertOk();
    $this->patchJson("/api/imports/sessions/{$id}/source", ['delimiter' => ',', 'header_row' => 1])->assertOk()->assertJsonPath('data.status', 'mapped');
    $this->putJson("/api/imports/sessions/{$id}/mapping", ['mapping' => ['Asset tag' => 'asset_tag', 'Name' => 'name']])->assertOk();

    $this->postJson("/api/imports/sessions/{$id}/preview")->assertAccepted()->assertJsonPath('data.status', 'previewed');
    $preview = $this->getJson("/api/imports/sessions/{$id}/preview")->assertOk();

    expect($preview->json('data.counts.valid'))->toBe(2)
        ->and($preview->json('data.plan'))->toBe(['creates' => 2, 'updates' => 1, 'skips' => 0])
        ->and($preview->json('data.update_notice'))->toContain('not reverted');

    $this->getJson("/api/imports/sessions/{$id}/rows?status=invalid")->assertOk()->assertJsonPath('data.0.row', 4)->assertJsonPath('data.0.errors.0.column', 'C');
    $updates = $this->getJson("/api/imports/sessions/{$id}/rows?status=updates")->assertOk();
    expect($updates->json('data.0.match.type'))->toBe('Asset')->and($updates->json('data.0.changes.0.field'))->toBe('name');
    $this->getJson("/api/imports/sessions/{$id}/rows?status=creates&per_page=1")->assertOk()->assertJsonPath('meta.total', 2);
    foreach (['duplicates', 'skips', 'all'] as $status) {
        $this->getJson("/api/imports/sessions/{$id}/rows?status={$status}")->assertOk();
    }
    $this->get("/api/imports/sessions/{$id}/error-report?format=csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $commit = $this->postJson("/api/imports/sessions/{$id}/commit")->assertAccepted();
    $batch = $commit->json('data.id');

    expect($commit->json('data.status'))->toBe('completed')->and($commit->json('data.summary.created'))->toBe(2);

    $this->getJson("/api/imports/batches/{$batch}")->assertOk()->assertJsonPath('data.can_rollback', true);
    $this->getJson("/api/imports/batches/{$batch}/records?action=created")->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.record.type', 'Asset');
    $this->postJson("/api/imports/batches/{$batch}/resume")->assertStatus(409);
    $this->postJson("/api/imports/batches/{$batch}/cancel")->assertStatus(409);
    $this->postJson("/api/imports/batches/{$batch}/rollback")->assertAccepted()->assertJsonPath('data.rollback.deleted', 2);

    expect(Asset::query()->count())->toBe(1);

    $this->deleteJson('/api/imports/importers/assets/mappings/API')->assertOk();
    $this->deleteJson('/api/imports/importers/assets/mappings/API')->assertNotFound();
});

test('templates_and_file_batches_work_through_the_api', function (): void {
    $team = team();
    $this->actingAs(admin($team));

    $this->get('/api/imports/importers/people/template?format=csv&locale=sk')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=people.csv');

    $files = $this->post('/api/imports/importers/documents/file-sessions', ['files' => [SpreadsheetFactory::file('a.pdf', 'x'), SpreadsheetFactory::file('b.txt', 'y')]], ['Accept' => 'application/json'])->assertCreated();
    expect($files->json('data.kind'))->toBe('files')->and($files->json('data.data_rows'))->toBe(2);

    $other = $this->post('/api/imports/importers/assets/sessions', ['file' => assetsCsv(assetRows(1))], ['Accept' => 'application/json'])->json('data.id');
    $this->deleteJson("/api/imports/sessions/{$other}")->assertNoContent();
    $this->getJson("/api/imports/sessions/{$other}")->assertNotFound();
});

test('the_api_answers_401_without_a_user', function (): void {
    $this->getJson('/api/imports/importers')->assertUnauthorized();
    $this->postJson('/api/imports/importers/assets/sessions')->assertUnauthorized();
});

test('the_api_refuses_another_owners_session', function (): void {
    $theirs = team();
    $session = uploadAssets($theirs, admin($theirs), assetsCsv(assetRows(1)));
    $batch = $session->preview()->commit();
    $mine = team();

    $this->actingAs(admin($mine));
    $this->getJson('/api/imports/sessions/'.$session->id())->assertNotFound();
    $this->getJson('/api/imports/batches/'.$batch->id())->assertNotFound();
    $this->get('/api/imports/importers/assets/template')->assertOk();

    $this->actingAs(admin($theirs)->forceFill(['is_admin' => false]));
    $this->get('/api/imports/importers/assets/template')->assertForbidden();
});

test('uploads_are_rate_limited', function (): void {
    $team = team();
    $this->actingAs(admin($team));

    for ($i = 0; $i < 10; $i++) {
        $this->post('/api/imports/importers/assets/sessions', ['file' => assetsCsv(assetRows(1))], ['Accept' => 'application/json'])->assertCreated();
    }

    $this->post('/api/imports/importers/assets/sessions', ['file' => assetsCsv(assetRows(1))], ['Accept' => 'application/json'])->assertTooManyRequests();
});

test('upload_rejections_answer_422_with_a_clear_message', function (): void {
    $team = team();
    $this->actingAs(admin($team));

    $this->post('/api/imports/importers/assets/sessions', ['file' => SpreadsheetFactory::file('x.csv', '')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('message', 'The file is empty.');
    $this->post('/api/imports/importers/nope/sessions', ['file' => assetsCsv(assetRows(1))], ['Accept' => 'application/json'])->assertNotFound();
    $this->post('/api/imports/importers/assets/sessions', [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('the_api_never_exposes_file_paths', function (): void {
    $team = team();
    $this->actingAs(admin($team));
    $response = $this->post('/api/imports/importers/assets/sessions', ['file' => assetsCsv(assetRows(1))], ['Accept' => 'application/json'])->assertCreated();
    $session = ImportSession::query()->firstOrFail();

    expect($response->getContent())->not->toContain($session->file_path)->not->toContain('imports/')->not->toContain('file_disk')->not->toContain('lock_key');
});

test('owner_renders_a_basename_not_a_class_name', function (): void {
    $team = team();
    $response = (new ImportSessionResource(uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->model()))->toResponse(request())->getContent();

    expect($response)->not->toContain('\\\\')->not->toContain('Workbench')
        ->and(json_decode((string) $response, true)['data']['owner'])->toBe(['id' => $team->id, 'type' => 'Team'])
        ->and(json_decode((string) $response, true)['data']['actor']['type'])->toBe('User');
});

test('actor_renders_a_basename_not_a_class_name', function (): void {
    $user = admin(team());

    expect((new ImportActorResource($user))->resolve(request()))->toBe(['id' => $user->id, 'type' => 'User'])
        ->and(References::actor(null, request()))->toBeNull();
});

test('record_renders_a_basename_not_a_class_name', function (): void {
    expect((new ImportRecordResource(new RecordReference(Asset::class, 7)))->resolve(request()))->toBe(['id' => 7, 'type' => 'Asset'])
        ->and(References::record(null, request()))->toBeNull()
        ->and(json_encode(References::record(new RecordReference(Asset::class, 7), request())))->not->toContain('\\\\');
});

test('owner_honours_the_configured_resource', function (): void {
    config()->set('imports.resources.owner', FixtureOwnerResource::class);
    $team = team();

    expect(References::owner($team, request()))->toBe(['id' => $team->id, 'name' => $team->name, 'custom' => true]);
});

test('actor_and_record_honour_the_configured_resource', function (): void {
    config()->set('imports.resources.actor', FixtureOwnerResource::class);
    config()->set('imports.resources.record', ImportOwnerResource::class);

    expect(ImportsConfig::actorResource())->toBe(FixtureOwnerResource::class)
        ->and(ImportsConfig::recordResource())->toBe(ImportOwnerResource::class);
});

test('each_role_rejects_a_non_resource', function (string $role, mixed $value): void {
    config()->set('imports.resources.'.$role, $value);

    expect(fn () => match ($role) {
        'owner' => ImportsConfig::ownerResource(),
        'actor' => ImportsConfig::actorResource(),
        default => ImportsConfig::recordResource(),
    })->toThrow(InvalidImportsConfigurationException::class, 'imports.resources.'.$role);
})->with([
    'owner not a resource' => ['owner', stdClass::class],
    'actor bare base' => ['actor', JsonResource::class],
    'record not a class' => ['record', 'Nope\\Missing'],
    'owner not a string' => ['owner', 42],
]);

test('each_role_falls_back_when_blank', function (string $role, string $default): void {
    config()->set('imports.resources.'.$role, '');

    expect(match ($role) {
        'owner' => ImportsConfig::ownerResource(),
        'actor' => ImportsConfig::actorResource(),
        default => ImportsConfig::recordResource(),
    })->toBe($default);
})->with([
    ['owner', ImportOwnerResource::class],
    ['actor', ImportActorResource::class],
    ['record', ImportRecordResource::class],
]);

test('a_session_list_runs_a_fixed_number_of_queries', function (): void {
    $team = team();
    $user = admin($team);

    $count = static function (int $sessions) use ($team, $user): int {
        foreach (range(1, $sessions) as $i) {
            uploadAssets($team, $user, assetsCsv(assetRows(1)));
        }

        $list = Imports::for($team)->by($user)->sessions()->with(['owner', 'actor', 'batch'])->get();
        Database::flushQueryLog();
        Database::enableQueryLog();
        ImportSessionResource::collection($list)->toResponse(request());
        $queries = count(Database::getQueryLog());
        Database::disableQueryLog();

        return $queries;
    };

    expect($count(2))->toBe($count(5))->and(Imports::for($team)->by($user)->sessions()->count())->toBe(7);
});
