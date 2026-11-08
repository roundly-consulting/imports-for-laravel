<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Teams;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;

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
        return TeamAsset::class;
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
        return [Field::text('asset_tag'), Field::text('name')->required()];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return TeamAsset::query()->create([
            'team_id' => self::$writeTeam ?? $context->owner->getKey(),
            'asset_tag' => $row->get('asset_tag'),
            'name' => $row->get('name'),
        ]);
    }
}
