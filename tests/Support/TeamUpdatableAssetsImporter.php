<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use Workbench\App\Models\User;

/**
 * Team-context assets an import can also update — so a preview has "updates" rows with a diff.
 */
final class TeamUpdatableAssetsImporter extends Importer
{
    public function key(): string
    {
        return 'team-updatable-assets';
    }

    public function model(): string
    {
        return TeamScopedAsset::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function naturalKey(): NaturalKey
    {
        return NaturalKey::by('asset_tag');
    }

    public function fields(): array
    {
        return [Field::text('asset_tag')->label('Asset tag'), Field::text('name')->label('Name')->required()];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return $user instanceof User && $user->team_id === $owner->getKey();
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return TeamScopedAsset::query()->create(['team_id' => ContextTeams::require(), 'asset_tag' => $row->get('asset_tag'), 'name' => $row->get('name')]);
    }

    public function update(Model $record, ImportRow $row, ImportContext $context): Model
    {
        $record->fill(['name' => $row->get('name')])->save();

        return $record;
    }
}
