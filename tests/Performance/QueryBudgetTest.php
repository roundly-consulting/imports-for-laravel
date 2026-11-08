<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Hooks;
use Workbench\App\Models\Asset;

/**
 * Query budgets, not timings: they hold on every machine. The workbench `assets` importer, a
 * 1,000-row CSV, the queue off so every step runs inline.
 */
function queriesOf(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

beforeEach(fn () => config()->set('imports.queue.enabled', 'off'));

test('a_thousand_row_commit_stays_within_its_query_budget', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1000)))->preview();

    $queries = queriesOf(fn () => $session->commit());

    expect(Asset::query()->count())->toBe(1000)->and($queries)->toBeLessThanOrEqual(1150);
})->group('perf');

test('a_thousand_row_preview_stays_within_its_query_budget', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(1000)));

    expect(queriesOf(fn () => $session->preview()))->toBeLessThanOrEqual(40);
})->group('perf');

test('the_ledger_matches_the_written_records_after_a_bulk_insert', function (): void {
    config()->set('imports.commit.chunk_size', 7);
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(30)))->preview();
    $batch = $session->commit();

    $ledger = ImportedRecord::query()->orderBy('row_number')->get();

    expect($ledger)->toHaveCount(30)
        ->and($ledger->pluck('record_id')->map(fn ($id): int => (int) $id)->all())->toBe(Asset::query()->orderBy('id')->pluck('id')->all())
        ->and($ledger->pluck('row_number')->all())->toBe(range(2, 31))
        ->and($ledger->every(fn (ImportedRecord $r): bool => $r->action === RecordAction::Created && $r->checksum !== null && (string) $r->batch_id === (string) $batch->id()))->toBeTrue();
});

test('a_failed_row_leaves_no_ledger_row', function (): void {
    Hooks::$onCreate = static function (int $row): void {
        if ($row === 3) {
            throw new RuntimeException('host refused');
        }
    };
    $team = team();
    uploadAssets($team, admin($team), assetsCsv(assetRows(3)))->preview()->commit();

    expect(ImportedRecord::query()->pluck('row_number')->all())->toBe([2, 4])
        ->and(ImportRowResult::query()->where('row_number', 3)->value('status'))->toBe(RowStatus::Failed)
        ->and(Asset::query()->count())->toBe(2);
});

test('a_thousand_row_rollback_stays_within_its_query_budget', function (): void {
    $team = team();
    $batch = uploadAssets($team, admin($team), assetsCsv(assetRows(1000)))->preview()->commit();

    $queries = queriesOf(fn () => $batch->rollback());

    expect(Asset::query()->count())->toBe(0)->and($queries)->toBeLessThanOrEqual(4100);
})->group('perf');

test('ten_thousand_rows_report_their_timings', function (string $format): void {
    $team = team();
    $user = admin($team);
    $headers = assetHeaders();
    $rows = assetRows(10000);
    $file = $format === 'xlsx' ? SpreadsheetFactory::xlsx($headers, $rows) : SpreadsheetFactory::csv($headers, $rows);
    $timings = [];
    $queries = [];

    $start = hrtime(true);
    $session = uploadAssets($team, $user, $file);
    $timings['upload'] = hrtime(true) - $start;
    $start = hrtime(true);
    $queries['preview'] = queriesOf(fn () => $session->preview());
    $timings['preview'] = hrtime(true) - $start;
    $start = hrtime(true);
    $queries['commit'] = queriesOf(fn () => $session->commit());
    $timings['commit'] = hrtime(true) - $start;

    fwrite(STDERR, sprintf("\n  %s 10k: %s\n", $format, implode(' · ', array_map(
        static fn (string $step, int $ns): string => sprintf('%s %.2f s / %d q', $step, $ns / 1e9, $queries[$step] ?? 0),
        array_keys($timings),
        $timings,
    ))));

    expect($queries['commit'])->toBeLessThanOrEqual(11500);
})->with(['csv', 'xlsx'])->group('perf')->skip(fn (): bool => getenv('IMPORTS_PERF') !== '1', 'set IMPORTS_PERF=1 for the 10k timing report');
