<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Docs\Readme\AssetsImporter;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;
use Workbench\Database\Factories\AssetFactory;
use Workbench\Database\Factories\VendorFactory;

/**
 * The README's importer block, as the README holds it.
 */
function readmeImporterBlock(): string
{
    preg_match('/## Usage.*?```php\n(.*?)```/s', (string) file_get_contents(dirname(__DIR__, 2).'/README.md'), $block);

    return $block[1] ?? '';
}

beforeEach(function (): void {
    config()->set('imports.importers', [AssetsImporter::class]);
    config()->set('imports.queue.enabled', 'off');
    Gate::define('manage-assets', static fn (User $user, Team $team): bool => $user->team_id === $team->id);
});

test('the_readme_importer_is_a_byte_identical_copy', function (): void {
    $copy = (string) file_get_contents(__DIR__.'/Readme/AssetsImporter.php');
    $body = substr($copy, (int) strpos($copy, 'use '));

    expect(readmeImporterBlock())->not->toBe('')
        ->and($body)->toBe(str_replace('use App\Models\\', 'use Workbench\App\Models\\', readmeImporterBlock()));
});

test('the_readme_importer_runs_the_readme_flow', function (): void {
    $team = team();
    $user = admin($team);
    $vendor = VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Lenovo', 'vat_id' => 'SK100']);
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'serial_number' => 'SN-KEPT', 'name' => 'Old']);
    $rows = [];

    foreach (range(1, 50) as $i) {
        // Row 1 updates the existing asset and leaves its serial number blank.
        $rows[] = [sprintf('AST-%04d', $i), $i === 1 ? '' : sprintf('SN-%05d', $i), 'Laptop '.$i, '2026-01-15', $i % 2 === 0 ? 'SK100' : ''];
    }

    $file = SpreadsheetFactory::csv(['Asset tag', 'Serial number', 'Name', 'Purchased on', 'Vendor'], $rows);

    // The README flow, call for call.
    $import = Imports::for($team)->by($user)
        ->upload('assets', $file)
        ->mode(DuplicateMode::Update)
        ->preview();

    expect($import->isPreviewed())->toBeTrue()
        ->and($import->counts()->valid)->toBe(49)
        ->and($import->counts()->matched)->toBe(1);

    $batch = $import->commit();

    $updated = Asset::query()->where('asset_tag', 'AST-0001')->sole();
    expect(Asset::query()->count())->toBe(50)
        ->and($updated->name)->toBe('Laptop 1')
        ->and($updated->serial_number)->toBe('SN-KEPT')
        ->and(Asset::query()->where('asset_tag', 'AST-0002')->sole()->vendor_id)->toBe($vendor->id)
        ->and(Asset::query()->where('asset_tag', 'AST-0003')->sole()->vendor_id)->toBeNull()
        ->and($batch->summary()->created)->toBe(49)
        ->and($batch->summary()->updated)->toBe(1);

    $batch->rollback();

    expect($batch->model()->refresh()->rollback_status)->toBe(RollbackStatus::RolledBack)
        ->and(Asset::query()->pluck('asset_tag')->all())->toBe(['AST-0001']);
});

test('a_blank_cell_keeps_the_stored_value_in_the_readme_update', function (): void {
    $team = team();
    $user = admin($team);
    AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'AST-0001', 'serial_number' => 'SN-KEPT', 'name' => 'Old', 'purchased_on' => '2025-01-01']);
    $file = SpreadsheetFactory::csv(['Asset tag', 'Serial number', 'Name', 'Purchased on'], [['AST-0001', '', 'New name', '']]);

    Imports::for($team)->by($user)->upload('assets', $file)->mode(DuplicateMode::Update)->preview()->commit();

    $asset = Asset::query()->sole();
    expect($asset->name)->toBe('New name')
        ->and($asset->serial_number)->toBe('SN-KEPT')
        ->and(substr((string) $asset->getRawOriginal('purchased_on'), 0, 10))->toBe('2025-01-01');
});
