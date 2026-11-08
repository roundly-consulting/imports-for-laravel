<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Exceptions\ImportModeNotSupportedException;
use RoundlyConsulting\Imports\Exceptions\ImportNotFoundException;
use RoundlyConsulting\Imports\Exceptions\ImportUnauthorizedException;
use RoundlyConsulting\Imports\Exceptions\UnscopedQueryException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\ContextOwnerScope;
use RoundlyConsulting\Imports\Tests\Support\ContextTeams;
use RoundlyConsulting\Imports\Tests\Support\TeamAssetsImporter;
use RoundlyConsulting\Imports\Tests\Support\TeamScopedAsset;
use Workbench\App\Models\User;
use Workbench\App\Models\Vendor;
use Workbench\Database\Factories\VendorFactory;

test('a_session_of_another_owner_is_not_found', function (): void {
    $mine = team();
    $theirs = team();
    $session = uploadAssets($theirs, admin($theirs), assetsCsv(assetRows(1)));

    expect(fn () => Imports::for($mine)->session($session->id()))->toThrow(ImportNotFoundException::class)
        ->and(fn () => Imports::for($mine)->session('999999'))->toThrow(ImportNotFoundException::class)
        ->and(Imports::for($theirs)->session($session->id())->id())->toBe($session->id())
        ->and(Imports::for($mine)->by(admin($mine))->sessions()->count())->toBe(0)
        ->and(Imports::for($theirs)->by(admin($theirs))->sessions('assets')->count())->toBe(1);
});

test('a_batch_of_another_owner_is_not_found', function (): void {
    $mine = team();
    $theirs = team();
    $batch = uploadAssets($theirs, admin($theirs), assetsCsv(assetRows(1)))->preview()->commit();

    expect(fn () => Imports::for($mine)->batch($batch->id()))->toThrow(ImportNotFoundException::class)
        ->and(Imports::for($theirs)->batch($batch->id())->id())->toBe($batch->id())
        ->and(fn () => Imports::batch('424242'))->toThrow(ImportNotFoundException::class)
        ->and(fn () => Imports::session('424242'))->toThrow(ImportNotFoundException::class);
});

test('authorize_is_checked_at_upload_commit_rollback_and_view', function (): void {
    $team = team();
    $admin = admin($team);
    $stranger = admin(team());
    $session = uploadAssets($team, $admin, assetsCsv(assetRows(1)));

    $denied = static fn (Closure $do) => expect($do)->toThrow(ImportUnauthorizedException::class);

    $denied(fn () => uploadAssets($team, $stranger, assetsCsv(assetRows(1))));
    $denied(fn () => $session->by($stranger)->preview());
    $denied(fn () => $session->by($stranger)->map(['Asset tag' => 'asset_tag', 'Name' => 'name']));
    $denied(fn () => $session->by($stranger)->mode(DuplicateMode::Update));
    $denied(fn () => $session->by($stranger)->suggestMapping());
    $denied(fn () => $session->by($stranger)->rows());
    $denied(fn () => $session->by($stranger)->previewData());
    $denied(fn () => $session->by($stranger)->useDelimiter(';'));
    $denied(fn () => $session->by($stranger)->discard());
    $denied(fn () => Imports::for($team)->by($stranger)->mappings('assets')->all());

    $session->preview();
    $denied(fn () => $session->by($stranger)->commit());
    $denied(fn () => $session->by($stranger)->errorReport());

    $batch = $session->commit();
    $denied(fn () => $batch->by($stranger)->rollback());
    $denied(fn () => $batch->by($stranger)->cancel());
    $denied(fn () => $batch->by($stranger)->resume());
});

test('an_unauthorized_upload_stores_nothing', function (): void {
    $team = team();

    expect(fn () => uploadAssets($team, admin(team()), assetsCsv(assetRows(1))))->toThrow(ImportUnauthorizedException::class)
        ->and(ImportSession::query()->withTrashed()->count())->toBe(0)
        ->and(Storage::disk('imports')->allFiles())->toBe([]);
});

test('the_pipeline_never_queries_host_models_outside_the_owner_context', function (): void {
    // teams' `without_context = throw`: any query on a scoped model outside a team context throws.
    app()->bind(OwnerScope::class, ContextOwnerScope::class);
    Imports::register(TeamAssetsImporter::class);
    $team = team();
    VendorFactory::new()->create(['team_id' => $team->id, 'name' => 'Lenovo']);
    $user = admin($team);
    ContextTeams::set(null);

    expect(fn () => TeamScopedAsset::query()->count())->toThrow(RuntimeException::class, 'No team context');

    $file = SpreadsheetFactory::csv(['Asset tag', 'Name', 'Vendor'], [['T-1', 'One', 'Lenovo'], ['T-2', 'Two', '']]);
    $batch = Imports::for($team)->by($user)->upload('team-assets', $file)->preview()->commit();

    $again = Imports::for($team)->by($user)->upload('team-assets', $file);
    expect(fn () => $again->mode(DuplicateMode::Update))->toThrow(ImportModeNotSupportedException::class);
    $again->preview();
    $again->rows(RowFilter::duplicates())->paginate();

    $batch->rollback();

    expect($batch->summary()->created)->toBe(2)
        ->and($again->counts()->matched)->toBe(2)
        ->and(ContextTeams::runAs($team, fn (): int => TeamScopedAsset::query()->count()))->toBe(0);
});

test('the_import_context_exposes_owner_scoped_queries', function (): void {
    $team = team();
    $other = team();
    VendorFactory::new()->create(['team_id' => $team->id]);
    VendorFactory::new()->create(['team_id' => $other->id]);

    $context = new ImportContext($team, null, Imports::importers()->find('assets'), 1, null, DuplicateMode::Skip, 'en', 'UTC', app(OwnerScope::class));

    expect($context->query(Vendor::class)->count())->toBe(1)
        ->and($context->ownerAttributes())->toBe(['team_id' => $team->id])
        ->and($context->scope())->toBeInstanceOf(OwnerScope::class);
});

test('every_ownership_rule_constrains_and_checks', function (): void {
    $scope = new ExplicitOwnerScope;
    $team = team();
    $user = admin($team);
    $other = admin(team());
    $rules = [
        'column' => Ownership::column('team_id'),
        'belongsTo' => Ownership::belongsTo('team'),
        'using' => Ownership::using(
            static fn ($q, $owner) => $q->where('team_id', $owner->getKey()),
            static fn ($record, $owner): bool => $record->team_id === $owner->getKey(),
        ),
        'global' => Ownership::global(),
    ];

    foreach ($rules as $name => $rule) {
        $count = $scope->constrain(User::query(), $team, $rule)->count();

        expect($count)->toBe($name === 'global' ? 2 : 1, $name)
            ->and($scope->owns($user, $team, $rule))->toBeTrue($name)
            ->and($scope->owns($other, $team, $rule))->toBe($name === 'global', $name);
    }

    $morph = Ownership::morph('owner');
    $session = ImportSession::factory()->owner($team)->create();
    expect($scope->constrain(ImportSession::query(), $team, $morph)->count())->toBe(1)
        ->and($scope->owns($session, $team, $morph))->toBeTrue()
        ->and($morph->attributes($team))->toBe(['owner_type' => $team->getMorphClass(), 'owner_id' => $team->id])
        ->and($morph->describe())->toBe('morph(owner)')
        ->and($scope->owns($user, $team, Ownership::belongsTo('missing')))->toBeFalse()
        ->and(fn () => $scope->constrain(User::query(), $team, Ownership::inherit()))->toThrow(UnscopedQueryException::class);
});
