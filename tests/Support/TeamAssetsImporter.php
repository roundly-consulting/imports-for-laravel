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
use Workbench\App\Models\Vendor;

/**
 * Assets under the team-context scope: the importer writes through the ambient team only.
 */
final class TeamAssetsImporter extends Importer
{
    public static ?int $writeTeam = null;

    public function key(): string
    {
        return 'team-assets';
    }

    public function label(): string
    {
        return 'Team assets';
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
        return [
            Field::text('asset_tag')->label('Asset tag'),
            Field::text('name')->label('Name')->required(),
            Field::reference('vendor')->label('Vendor')->to(Vendor::class)->by('name'),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return $user instanceof User && $user->team_id === $owner->getKey();
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return TeamScopedAsset::query()->create([
            'team_id' => self::$writeTeam ?? ContextTeams::require(),
            'asset_tag' => $row->get('asset_tag'),
            'name' => $row->get('name'),
            'vendor_id' => $row->reference('vendor')?->getKey(),
        ]);
    }
}
