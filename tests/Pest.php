<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\TestCase;
use Workbench\App\Hooks;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;
use Workbench\Database\Factories\TeamFactory;
use Workbench\Database\Factories\UserFactory;

uses(TestCase::class)
    ->beforeEach(function (): void {
        Storage::fake('imports');
        Storage::fake('documents');
        Hooks::reset();
    })
    ->in(__DIR__);

function team(): Team
{
    return TeamFactory::new()->create();
}

function admin(Team $team): User
{
    return UserFactory::new()->create(['team_id' => $team->id]);
}

/**
 * The asset header row as a user's export would have it.
 *
 * @return list<string>
 */
function assetHeaders(): array
{
    return ['Asset tag', 'Serial number', 'Name', 'Status', 'Purchased on', 'Price', 'Insured', 'Vendor', 'Assignee'];
}

/**
 * `$count` valid asset rows, numbered from `$from`.
 *
 * @return list<list<string>>
 */
function assetRows(int $count, int $from = 1): array
{
    $rows = [];

    for ($i = $from; $i < $from + $count; $i++) {
        $rows[] = [sprintf('AST-%04d', $i), sprintf('SN-%05d', $i), 'Laptop '.$i, 'active', '2026-01-15', '1299.50', 'yes', '', ''];
    }

    return $rows;
}

/**
 * @param  list<list<string|int|float|bool|null>>  $rows
 */
function assetsCsv(array $rows, string $delimiter = ','): UploadedFile
{
    return SpreadsheetFactory::csv(assetHeaders(), $rows, $delimiter);
}

function uploadAssets(Team $team, User $user, UploadedFile $file): SessionHandle
{
    return Imports::for($team)->by($user)->upload('assets', $file);
}
