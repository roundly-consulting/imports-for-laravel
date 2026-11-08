<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Reading\SpreadsheetFile;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Http\HttpTestCase;
use RoundlyConsulting\Imports\Tests\Install\InstallTestCase;
use RoundlyConsulting\Imports\Tests\KeyTypes\KeyTypesTestCase;
use RoundlyConsulting\Imports\Tests\Support\XlsxBuilder;
use RoundlyConsulting\Imports\Tests\TestCase;
use Workbench\App\Hooks;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;
use Workbench\Database\Factories\TeamFactory;
use Workbench\Database\Factories\UserFactory;

$fresh = function (): void {
    Storage::fake('imports');
    Storage::fake('documents');
    Hooks::reset();
};

uses(TestCase::class)->beforeEach($fresh)->in('Acceptance', 'Arch', 'Feature', 'Memory', 'RealEngine', 'Teams');
uses(HttpTestCase::class)->beforeEach($fresh)->in('Http');
uses(KeyTypesTestCase::class)->beforeEach($fresh)->in('KeyTypes');
uses(InstallTestCase::class)->beforeEach($fresh)->in('Install');

/**
 * Run the database queue worker like a supervisor would: a fresh worker whenever one stops
 * (a lost connection stops a worker on purpose), until the queue is empty.
 */
function drainQueue(string $queue = 'default,imports'): void
{
    for ($run = 0; $run < 10; $run++) {
        app()->forgetInstance('queue.worker');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);

        if (DB::table('jobs')->whereIn('queue', explode(',', $queue))->count() === 0) {
            return;
        }
    }
}

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

/**
 * @return array<int, list<string|null>>
 */
function displayRows(SpreadsheetFile $file): array
{
    $rows = [];

    foreach ($file->rows() as $number => $cells) {
        $rows[$number] = array_map(static fn (?Cell $cell): ?string => $cell?->display(), $cells);
    }

    return $rows;
}

function csvFile(string $content): string
{
    $path = XlsxBuilder::path('csv');
    file_put_contents($path, $content);

    return $path;
}

function rejectionOf(Closure $read): ImportFileRejectedException
{
    try {
        $read();
    } catch (ImportFileRejectedException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected a rejection.');
}
