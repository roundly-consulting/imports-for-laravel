<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\ChangeDetection;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use Workbench\App\Models\Asset;

/**
 * Assets owned by "the team, or the shared pool (team 0)": an `Ownership::using()` rule whose
 * query closure ends in an ungrouped `orWhere`, as a host would naturally write it.
 */
final class SharedAssetsImporter extends Importer
{
    public function key(): string
    {
        return 'shared-assets';
    }

    public function model(): string
    {
        return Asset::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::using(
            query: static fn (Builder $query, Model $team): Builder => $query->where('team_id', $team->getKey())->orWhere('team_id', 0),
            owns: static fn (Model $asset, Model $team): bool => in_array((int) $asset->getAttribute('team_id'), [(int) $team->getKey(), 0], true),
        );
    }

    public function changeDetection(): ChangeDetection
    {
        return ChangeDetection::updatedAt();
    }

    public function fields(): array
    {
        return [Field::text('asset_tag')->label('Asset tag'), Field::text('name')->label('Name')->required()];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return Asset::query()->create(['asset_tag' => $row->get('asset_tag'), 'name' => $row->get('name'), 'team_id' => $context->owner->getKey()]);
    }
}
