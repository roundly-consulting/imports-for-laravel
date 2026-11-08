<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\KeyTypes;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;

final class UuidItemsImporter extends Importer
{
    public function key(): string
    {
        return 'items';
    }

    public function label(): string
    {
        return 'Items';
    }

    public function model(): string
    {
        return UuidItem::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function naturalKey(): NaturalKey
    {
        return NaturalKey::by('code');
    }

    public function fields(): array
    {
        return [Field::text('code')->required(), Field::text('name')->required()];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return UuidItem::query()->create([...$row->all(), ...$context->ownerAttributes()]);
    }
}
