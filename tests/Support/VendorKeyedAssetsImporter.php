<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\FileKey;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Vendor;

/**
 * Assets keyed by "name + vendor": the natural key holds a reference, resolved inside the team.
 * The switches are reset before every test.
 */
final class VendorKeyedAssetsImporter extends Importer
{
    public static ?NaturalKey $naturalKey = null;

    public static ?string $labelField = null;

    public static ?FileKey $fileKey = null;

    public static function reset(): void
    {
        self::$naturalKey = null;
        self::$labelField = null;
        self::$fileKey = null;
    }

    public function uniqueInFile(): ?FileKey
    {
        return self::$fileKey;
    }

    public function key(): string
    {
        return 'vendor-assets';
    }

    public function model(): string
    {
        return Asset::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function naturalKey(): NaturalKey
    {
        return self::$naturalKey ?? NaturalKey::by('name', 'vendor');
    }

    public function labelField(): ?string
    {
        return self::$labelField;
    }

    public function fields(): array
    {
        return [
            Field::text('name')->label('Name')->required(),
            Field::reference('vendor')->label('Vendor')->to(Vendor::class)->by('vat_id', 'name')->storeAs('vendor_id'),
            Field::text('serial_number')->label('Serial number'),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return Asset::query()->create([...$row->attributes(), ...$context->ownerAttributes()]);
    }

    public function update(Model $record, ImportRow $row, ImportContext $context): Model
    {
        $record->fill($row->changes())->save();

        return $record;
    }
}
