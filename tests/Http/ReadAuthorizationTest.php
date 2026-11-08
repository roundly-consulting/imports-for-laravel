<?php

declare(strict_types=1);

use Workbench\Database\Factories\UserFactory;

/**
 * Every session and batch route, as [method, path] with `{s}` / `{b}` for the ids.
 *
 * @return array<string, array{string, string}>
 */
function everySessionAndBatchRoute(): array
{
    return [
        'show session' => ['GET', 'sessions/{s}'],
        'source' => ['PATCH', 'sessions/{s}/source'],
        'discard' => ['DELETE', 'sessions/{s}'],
        'suggest mapping' => ['GET', 'sessions/{s}/mapping'],
        'map' => ['PUT', 'sessions/{s}/mapping'],
        'save mapping' => ['POST', 'sessions/{s}/mapping/save'],
        'mode' => ['PUT', 'sessions/{s}/mode'],
        'run preview' => ['POST', 'sessions/{s}/preview'],
        'show preview' => ['GET', 'sessions/{s}/preview'],
        'rows' => ['GET', 'sessions/{s}/rows'],
        'error report' => ['GET', 'sessions/{s}/error-report'],
        'commit' => ['POST', 'sessions/{s}/commit'],
        'show batch' => ['GET', 'batches/{b}'],
        'cancel' => ['POST', 'batches/{b}/cancel'],
        'resume' => ['POST', 'batches/{b}/resume'],
        'rollback' => ['POST', 'batches/{b}/rollback'],
        'records' => ['GET', 'batches/{b}/records'],
    ];
}

test('the_api_answers_403_on_every_read_for_a_user_without_view', function (string $path): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(2)))->preview()->commit();
    $member = UserFactory::new()->create(['team_id' => $team->id, 'is_admin' => false]);

    $this->actingAs($member)
        ->getJson('/api/imports/'.strtr($path, ['{s}' => (string) $batch->session()->id(), '{b}' => (string) $batch->id()]))
        ->assertForbidden();
})->with([
    'session' => ['sessions/{s}'],
    'mapping' => ['sessions/{s}/mapping'],
    'preview' => ['sessions/{s}/preview'],
    'rows' => ['sessions/{s}/rows'],
    'error report' => ['sessions/{s}/error-report'],
    'batch' => ['batches/{b}'],
    'records' => ['batches/{b}/records'],
]);

test('every_session_and_batch_route_refuses_another_owner', function (string $method, string $path): void {
    $theirs = team();
    $batch = uploadAssets($theirs, admin($theirs), assetsCsv(assetRows(2)))->preview()->commit();
    $mine = team();

    $this->actingAs(admin($mine))
        ->json($method, '/api/imports/'.strtr($path, ['{s}' => (string) $batch->session()->id(), '{b}' => (string) $batch->id()]), ['mapping' => ['Name' => 'name'], 'mode' => 'skip', 'name' => 'x'])
        ->assertNotFound();
})->with(everySessionAndBatchRoute());

test('the_route_list_covers_every_session_and_batch_route', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_contains($route->uri(), 'imports/sessions/') || str_contains($route->uri(), 'imports/batches/'))
        ->count();

    expect(everySessionAndBatchRoute())->toHaveCount($routes);
});
