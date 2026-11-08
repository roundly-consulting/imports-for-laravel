<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;

// Dual review 2026-11-08 — the JSON API halves of the findings.

it('O-3: the mapping endpoint maps a numeric header by text and takes explicit column indexes', function (): void {
    $team = team();
    $this->actingAs(admin($team));
    $id = $this->post('/api/imports/importers/assets/sessions', ['file' => SpreadsheetFactory::csv(['Asset tag', 'Name', '2024'], [['A-1', 'Laptop', 'x']])], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $this->putJson("/api/imports/sessions/{$id}/mapping", ['mapping' => ['Asset tag' => 'asset_tag', '2024' => 'name']])->assertOk()
        ->assertJsonPath('data.mapping', [['column' => 0, 'field' => 'asset_tag'], ['column' => 2, 'field' => 'name']]);

    $this->putJson("/api/imports/sessions/{$id}/mapping", ['columns' => [['column' => 0, 'field' => 'asset_tag'], ['column' => 1, 'field' => 'name'], ['column' => 2, 'field' => null]]])->assertOk()
        ->assertJsonPath('data.mapping', [['column' => 0, 'field' => 'asset_tag'], ['column' => 1, 'field' => 'name']]);

    $this->putJson("/api/imports/sessions/{$id}/mapping", ['columns' => [['column' => 'x', 'field' => 'name']]])->assertUnprocessable()->assertJsonValidationErrors(['columns.0.column']);
    $this->putJson("/api/imports/sessions/{$id}/mapping", [])->assertUnprocessable()->assertJsonValidationErrors(['mapping']);
});

it('O-8: a malformed id in a route is a 404', function (): void {
    $this->actingAs(admin(team()));

    $this->getJson('/api/imports/sessions/abc')->assertNotFound();
    $this->getJson('/api/imports/batches/abc')->assertNotFound();
});

it('O-12: the error report endpoint renders its labels in the requested locale', function (): void {
    $team = team();
    $this->actingAs(admin($team));
    $rows = assetRows(2);
    $rows[1][2] = '';
    $id = $this->post('/api/imports/importers/assets/sessions', ['file' => assetsCsv($rows)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson("/api/imports/sessions/{$id}/preview")->assertSuccessful();

    expect($this->get("/api/imports/sessions/{$id}/error-report?format=csv&locale=sk")->assertOk()->streamedContent())->toContain(',Chyby')
        ->and($this->get("/api/imports/sessions/{$id}/error-report?format=csv")->assertOk()->streamedContent())->toContain(',Errors');
});

it('F-8: the error report of a file batch downloads over the API', function (): void {
    $team = team();
    $this->actingAs(admin($team));
    $id = $this->post('/api/imports/importers/documents/file-sessions', ['files' => [SpreadsheetFactory::file('a.exe', 'x'), SpreadsheetFactory::file('b.pdf', 'y')]], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson("/api/imports/sessions/{$id}/preview")->assertSuccessful();

    expect($this->get("/api/imports/sessions/{$id}/error-report?format=csv")->assertOk()->streamedContent())->toContain('a.exe');
});

// Dual review 2026-11-08, O-13 = F-5 + F-6: the config comments promised an upload budget "per user
// and owner" (it is per user, or per IP for guests) and an `ImportedRecordView` for the record
// resource (it receives a `RecordReference`).

it('O-13: the config comments describe the upload budget and the record resource as they work', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/imports.php');
    $docblock = (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/ImportsConfig.php');

    expect($config)->not->toContain('ImportedRecordView')->and($config)->toContain('RecordReference')
        ->and($config)->not->toContain('per user and owner')->and($docblock)->not->toContain('per user (and owner)');

    // The budget really is per user: one user's spent budget leaves a teammate's untouched.
    config()->set('imports.routes.upload_limit', 1);
    $team = team();
    $upload = fn () => $this->post('/api/imports/importers/assets/sessions', ['file' => assetsCsv(assetRows(1))], ['Accept' => 'application/json']);

    $this->actingAs(admin($team));
    $upload()->assertCreated();
    $upload()->assertTooManyRequests();

    $this->actingAs(admin($team));
    $upload()->assertCreated();
});
