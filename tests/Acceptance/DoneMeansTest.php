<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Invitation;
use Workbench\Database\Factories\VendorFactory;

/**
 * The owner brief's "Done means": the workbench app runs the full flow for both importers —
 * template, upload, mapping, preview, commit in the background on a real queue worker, undo.
 */
function runWorker(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'imports', '--stop-when-empty' => true, '--sleep' => 0]);
}

/**
 * The template's own header row, read back with the package reader.
 *
 * @return list<string|null>
 */
function templateHeaders(string $importer, string $locale): array
{
    $path = Imports::template($importer, TemplateFormat::Xlsx, $locale)->toTemporaryFile();

    try {
        return Imports::read($path)->headers();
    } finally {
        @unlink($path);
    }
}

beforeEach(function (): void {
    config()->set('imports.queue.connection', 'database');
    config()->set('imports.queue.queue', 'imports');
    config()->set('imports.preview.sync_threshold', 2);
});

test('the_people_importer_runs_the_full_flow', function (): void {
    $team = team();
    $user = admin($team);

    $headers = templateHeaders('people', 'sk');
    expect($headers)->toBe(['E-mail *', 'Meno', 'Priezvisko', 'Rola']);

    // Filled in by a person, plus a column the template does not have.
    $file = SpreadsheetFactory::xlsx([...$headers, 'Pozícia'], [
        ['ada@example.com', 'Ada', 'Lovelace', 'Administrátor', 'CTO'],
        ['alan@example.com', 'Alan', 'Turing', 'Člen', 'Engineer'],
        ['grace@example.com', 'Grace', 'Hopper', 'Pozorovateľ', 'Admiral'],
    ], sheet: 'Ľudia');

    $session = Imports::for($team)->by($user)->upload('people', $file, locale: 'sk');
    $suggestion = $session->suggestMapping();

    expect($session->status())->toBe(SessionStatus::Mapped)
        ->and($suggestion->isComplete())->toBeTrue()
        ->and($suggestion->ignoredColumns)->toBe([4]);

    // The user maps one column differently (the position becomes the first name) and saves it.
    $session->map(['E-mail *' => 'email', 'Pozícia' => 'first_name', 'Priezvisko' => 'last_name', 'Rola' => 'role'])->saveMapping('HR export');
    expect(Imports::for($team)->by($user)->mappings('people')->find('HR export'))->not->toBeNull();

    $session->preview();
    expect($session->status())->toBe(SessionStatus::Validating);
    runWorker();
    expect($session->status())->toBe(SessionStatus::Previewed)
        ->and($session->counts()->valid)->toBe(3)
        ->and(Invitation::query()->count())->toBe(0);

    $batch = $session->commit();
    expect($batch->status())->toBe(BatchStatus::Running)->and(Invitation::query()->count())->toBe(0);

    runWorker();

    expect($batch->status())->toBe(BatchStatus::Completed)
        ->and($batch->summary()->created)->toBe(3)
        ->and(Invitation::query()->orderBy('id')->pluck('first_name')->all())->toBe(['CTO', 'Engineer', 'Admiral'])
        ->and(Invitation::query()->orderBy('id')->pluck('role')->all())->toBe(['admin', 'member', 'viewer']);

    // Ada accepts before the undo: her invitation is kept, the rest are revoked by the hook.
    Invitation::query()->where('email', 'ada@example.com')->firstOrFail()->accept();

    $batch->rollback();
    runWorker();

    expect($batch->rollbackReport()?->handled)->toBe(2)
        ->and($batch->rollbackReport()?->keptChanged)->toBe(1)
        ->and(Invitation::query()->orderBy('id')->pluck('status')->all())->toBe(['accepted', 'revoked', 'revoked']);
});

test('the_assets_importer_runs_the_full_flow', function (): void {
    $team = team();
    $user = admin($team);
    VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Lenovo', 'vat_id' => 'SK2020123456']);

    $headers = templateHeaders('assets', 'sk');
    expect($headers[0])->toBe('Inventárne číslo')->and($headers[2])->toBe('Názov *');

    $rows = [];

    for ($i = 1; $i <= 6; $i++) {
        $rows[] = ['AST-'.$i, 'SN-'.$i, 'ThinkPad '.$i, 'Aktívny', '15.01.2026', '1 299,50', 'áno', 'SK2020123456', null];
    }

    $file = SpreadsheetFactory::xlsx($headers, $rows, sheet: 'Majetok');
    $session = Imports::for($team)->by($user)->upload('assets', $file, locale: 'sk');

    expect($session->status())->toBe(SessionStatus::Mapped);

    $session->preview();
    runWorker();

    expect($session->counts()->valid)->toBe(6)->and(Asset::query()->count())->toBe(0);

    $batch = $session->commit();
    runWorker();

    expect($batch->status())->toBe(BatchStatus::Completed)
        ->and(Asset::query()->count())->toBe(6)
        ->and((float) Asset::query()->firstOrFail()->price)->toBe(1299.5)
        ->and(Asset::query()->whereNotNull('vendor_id')->count())->toBe(6)
        ->and(Asset::query()->firstOrFail()->wasImported())->toBeTrue();

    Asset::query()->where('asset_tag', 'AST-3')->firstOrFail()->update(['name' => 'Repaired']);

    $batch->rollback();
    runWorker();

    expect(Asset::query()->pluck('asset_tag')->all())->toBe(['AST-3'])
        ->and($batch->rollbackReport()?->deleted)->toBe(5)
        ->and($batch->rollbackReport()?->keptChanged)->toBe(1);
});
