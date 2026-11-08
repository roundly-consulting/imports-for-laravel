<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Pipeline\Scoped;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\Imports\Tests\Support\SharedAssetsImporter;
use Workbench\App\Models\Asset;
use Workbench\Database\Factories\AssetFactory;

// Dual review 2026-11-08, O-1: a host `Ownership::using()` closure ending in `orWhere` was applied
// straight to the builder, so `… or team_id = 0 and id = ?` matched every record of the team.

it('O-1: an ungrouped using() rule cannot make undo delete a pre-existing record', function (): void {
    Imports::register(SharedAssetsImporter::class);
    $team = team();
    $user = admin($team);
    $before = AssetFactory::new()->create(['team_id' => $team->id, 'asset_tag' => 'OLD-1', 'name' => 'Pre-existing', 'serial_number' => null]);
    Asset::query()->whereKey($before->id)->update(['updated_at' => now()->subDays(10)]);

    $batch = Imports::for($team)->by($user)->upload('shared-assets', SpreadsheetFactory::csv(['Asset tag', 'Name'], [['NEW-1', 'Imported']]))->preview()->commit();
    $imported = Asset::query()->where('asset_tag', 'NEW-1')->firstOrFail();
    $batch->rollback();

    expect(Asset::withTrashed()->findOrFail($before->id)->deleted_at)->toBeNull()
        ->and(Asset::withTrashed()->findOrFail($imported->id)->deleted_at)->not->toBeNull()
        ->and($batch->rollbackReport()?->deleted)->toBe(1);
});

it('O-1: owner constraints are grouped, whatever the scope adds', function (): void {
    $team = team();
    $mine = AssetFactory::new()->create(['team_id' => $team->id]);
    $other = AssetFactory::new()->create(['team_id' => $team->id]);
    AssetFactory::new()->create(['team_id' => 0]);
    $rule = Ownership::using(
        query: static fn (Builder $query, Model $owner): Builder => $query->where('team_id', $owner->getKey())->orWhere('team_id', 0),
        owns: static fn (): bool => true,
    );

    // The package scope nests the host closure.
    expect(Scoped::query(Asset::class, $team, $rule, new ExplicitOwnerScope)->whereKey($mine->id)->pluck('id')->all())->toBe([$mine->id]);

    // A host OwnerScope that adds an ungrouped `or` is grouped by the one place queries start.
    $loose = new class implements OwnerScope
    {
        public function run(Model $owner, Closure $callback): mixed
        {
            return $callback();
        }

        public function constrain(Builder $query, Model $owner, Ownership $rule): Builder
        {
            return $query->where('team_id', $owner->getKey())->orWhere('team_id', 0);
        }

        public function owns(Model $record, Model $owner, Ownership $rule): bool
        {
            return true;
        }
    };

    expect(Scoped::query(Asset::class, $team, Ownership::column('team_id'), $loose)->whereKey($other->id)->pluck('id')->all())->toBe([$other->id])
        ->and(Scoped::query(Asset::class, $team, Ownership::column('team_id'), $loose, true)->whereKey($other->id)->pluck('id')->all())->toBe([$other->id]);
});
