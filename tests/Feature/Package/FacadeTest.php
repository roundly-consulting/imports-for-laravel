<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Imports\Actions\Sessions\UploadImportAction;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSource;
use RoundlyConsulting\Imports\DataTransferObjects\UploadData;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Testing\ImportsFake;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use Workbench\App\Models\Asset;

it('pins the facade contract', function (): void {
    expect(Imports::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../../src/Actions');
});

it('offers one API in three styles: facade, injected manager and the raw action', function (): void {
    $team = team();
    $user = admin($team);
    $file = assetsCsv(assetRows(1));

    $viaFacade = Imports::for($team)->by($user)->upload('assets', $file);
    $manager = app(ImportsManager::class);
    $viaManager = $manager->for($team)->by($user)->upload('assets', $file);
    $viaAction = app(UploadImportAction::class)->execute(new UploadData('assets', $team, $user, ImportSource::upload($file)));

    expect($manager)->toBe(app(ImportsManager::class))
        ->and([$viaFacade->status(), $viaManager->status(), $viaAction->status])->toBe([SessionStatus::Mapped, SessionStatus::Mapped, SessionStatus::Mapped])
        ->and(ImportSession::query()->count())->toBe(3);
});

it('resolves actions through the container so a host override applies', function (): void {
    app()->bind(UploadImportAction::class, static fn (): never => throw new RuntimeException('overridden'));
    $team = team();

    Imports::for($team)->by(admin($team))->upload('assets', assetsCsv(assetRows(1)));
})->throws(RuntimeException::class, 'overridden');

it('reaches sessions and batches through the trusted path and model conveniences', function (): void {
    $team = team();
    $handle = uploadAssets($team, admin($team), assetsCsv(assetRows(1)))->preview();
    $model = ImportSession::query()->firstOrFail();

    $batch = $model->handle()->by(admin($team))->commit();

    expect(Imports::session($model)->id())->toBe($model->id)
        ->and(Imports::session((string) $model->id)->model()->is($model))->toBeTrue()
        ->and(Imports::batch($batch->id())->model()->is($batch->model()))->toBeTrue()
        ->and(ImportBatch::query()->firstOrFail()->handle()->summary()->created)->toBe(1)
        ->and($handle->batch()?->id())->toBe($batch->id())
        ->and($batch->session()->id())->toBe($model->id)
        ->and($model->rowResults()->count())->toBe(0)
        ->and($model->count('valid'))->toBe(1)
        ->and(ImportBatch::query()->firstOrFail()->records()->count())->toBe(1);
});

it('reads progress, summary and records through the handles', function (): void {
    $team = team();
    $session = uploadAssets($team, admin($team), assetsCsv(assetRows(2)));

    expect($session->progress()->phase)->toBe('preview');

    $batch = $session->preview()->commit();

    expect($batch->progress()->percent)->toBe(100)
        ->and($batch->summary()->durationSeconds)->toBeInt()
        ->and($batch->records()->count())->toBe(2)
        ->and($session->batch()?->status())->toBe(BatchStatus::Completed)
        ->and(Imports::for($team)->running('assets'))->toBeNull();
});

it('records facade, injected and model-convenience calls under the fake', function (): void {
    $fake = Imports::fake();
    $team = team();
    $user = admin($team);

    Imports::for($team)->by($user)->upload('assets', assetsCsv(assetRows(2)))->preview();
    $session = ImportSession::query()->firstOrFail();
    $batch = $session->handle()->by($user)->commit();
    app(ImportsManager::class)->template('people', TemplateFormat::Csv, 'sk');

    expect(app(ImportsManager::class))->toBeInstanceOf(ImportsFake::class);
    $fake->assertUploaded('assets', static fn (ImportSession $s): bool => $s->owner_id === $team->id);
    $fake->assertPreviewed();
    $fake->assertCommitted('assets', static fn (ImportBatch $b): bool => $b->is($batch->model()));
    $fake->assertTemplateBuilt('people', TemplateFormat::Csv, 'sk');
    $fake->assertNothingRolledBack();
    Imports::assertUploaded();
    Imports::assertCommitted();
});

it('fails each fake assertion that did not happen', function (string $assertion, array $arguments): void {
    $fake = Imports::fake();

    expect(fn () => $fake->{$assertion}(...$arguments))->toThrow(ExpectationFailedException::class);
})->with([
    'uploaded' => ['assertUploaded', []],
    'uploaded importer' => ['assertUploaded', ['assets']],
    'files' => ['assertFilesUploaded', []],
    'mapped' => ['assertMapped', []],
    'mapping saved' => ['assertMappingSaved', ['ERP']],
    'mapping saved any' => ['assertMappingSaved', []],
    'previewed' => ['assertPreviewed', []],
    'committed' => ['assertCommitted', []],
    'committed importer' => ['assertCommitted', ['assets']],
    'cancelled' => ['assertCancelled', []],
    'resumed' => ['assertResumed', []],
    'rolled back' => ['assertRolledBack', []],
    'discarded' => ['assertDiscarded', []],
    'template' => ['assertTemplateBuilt', ['assets']],
    'error report' => ['assertErrorReportBuilt', []],
    'pruned' => ['assertPruned', []],
    'swept' => ['assertSwept', []],
]);

it('fails each assertNothing* after the operation happened', function (): void {
    Bus::fake();
    $fake = Imports::fake();
    $team = team();
    $user = admin($team);

    $session = Imports::for($team)->by($user)->upload('assets', SpreadsheetFactory::csv(['Inv', 'Name'], [['A', 'B']]));
    $session->map(['Inv' => 'asset_tag', 'Name' => 'name'])->saveMapping('ERP')->mode(DuplicateMode::Skip)->preview();
    $session->errorReport(TemplateFormat::Csv);
    Imports::for($team)->by($user)->uploadFiles('documents', SpreadsheetFactory::file('a.pdf', 'x'))->discard();
    $batch = $session->commit();
    $batch->cancel();
    Imports::template('assets');
    Imports::prune();
    Imports::sweep();
    Imports::for($team)->by($user)->mappings('assets')->delete('ERP');

    $fake->assertMapped();
    $fake->assertMappingSaved('ERP');
    $fake->assertFilesUploaded('documents');
    $fake->assertDiscarded();
    $fake->assertCancelled();
    $fake->assertErrorReportBuilt();
    $fake->assertPruned();
    $fake->assertSwept();

    foreach ([
        'assertNothingUploaded', 'assertNothingMapped', 'assertNoMappingSaved', 'assertNothingPreviewed',
        'assertNothingCommitted', 'assertNothingCancelled', 'assertNothingDiscarded', 'assertNoTemplateBuilt',
        'assertNoErrorReportBuilt', 'assertNotPruned', 'assertNotSwept', 'assertNothingHappened',
    ] as $assertion) {
        expect(fn () => $fake->{$assertion}())->toThrow(ExpectationFailedException::class);
    }

    expect($fake->recorded('deleteMapping'))->toHaveCount(1);
});

it('passes every assertNothing* on an untouched fake', function (): void {
    $fake = Imports::fake();

    foreach ([
        'assertNothingUploaded', 'assertNothingMapped', 'assertNoMappingSaved', 'assertNothingPreviewed',
        'assertNothingCommitted', 'assertNothingCancelled', 'assertNothingResumed', 'assertNothingRolledBack',
        'assertNothingDiscarded', 'assertNoTemplateBuilt', 'assertNoErrorReportBuilt', 'assertNotPruned',
        'assertNotSwept', 'assertNothingHappened',
    ] as $assertion) {
        $fake->{$assertion}();
    }

    expect($fake->recorded())->toBe([]);
});

it('records without running the return-less operations', function (): void {
    $team = team();
    $user = admin($team);
    $batch = uploadAssets($team, $user, assetsCsv(assetRows(1)))->preview()->commit();
    $fake = Imports::fake()->withoutRunning();

    Imports::batch($batch->id())->by($user)->rollback()->resume();
    Imports::sweep();

    $fake->assertRolledBack(static fn (ImportBatch $b): bool => $b->is($batch->model()));
    $fake->assertResumed();
    expect(Asset::query()->count())->toBe(1)
        ->and(fn () => $fake->assertRolledBack(static fn (): bool => false))->toThrow(ExpectationFailedException::class);
});

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $alias = (string) array_key_first($aliases);
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe([class_basename(Imports::class) => Imports::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\'.$alias))->toBeFalse()
        ->and($coreAliases)->not->toContain(strtolower($alias));
});
