<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Exceptions\ImportNotFoundException;
use RoundlyConsulting\Imports\Facades\Imports;

// Dual review 2026-11-08, O-8: an id that cannot be a key of the package tables reached the query;
// PostgreSQL refuses `'abc'` for a bigint/uuid column (SQLSTATE 22P02) — a 500, not a 404.
// The pgsql CI leg runs this against the real engine.

it('O-8: a malformed session or batch id is not found, never a query error', function (string $id): void {
    $team = team();

    expect(fn () => Imports::for($team)->session($id))->toThrow(ImportNotFoundException::class)
        ->and(fn () => Imports::for($team)->batch($id))->toThrow(ImportNotFoundException::class)
        ->and(fn () => Imports::session($id))->toThrow(ImportNotFoundException::class)
        ->and(fn () => Imports::batch($id))->toThrow(ImportNotFoundException::class);
})->with(['abc', '1abc', '-1', '0', '99999999999999999999', '018f3a7e-9c1b-7d2e-8a4b-1c2d3e4f5a6b']);
