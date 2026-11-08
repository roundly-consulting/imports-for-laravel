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
