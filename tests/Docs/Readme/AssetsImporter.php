<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Docs\Readme;

// A byte-identical copy of the README's importer (ReadmeExampleTest checks it): only the
// `App\Models\…` imports read `Workbench\App\Models\…`. Excluded from Pint on purpose.

use Workbench\App\Models\Asset;
use Workbench\App\Models\Vendor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\{Field, NaturalKey, Ownership};
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\{ImportContext, ImportRow};

final class AssetsImporter extends Importer   // key `assets`, label `Assets`
{
    public function model(): string { return Asset::class; }
    public function ownership(): Ownership { return Ownership::column('team_id'); }
    public function naturalKey(): NaturalKey { return NaturalKey::by('asset_tag')->then('serial_number'); }

    public function fields(): array
    {
        return [
            Field::text('asset_tag')->aliases('tag', 'inventory no'),
            Field::text('serial_number'),
            Field::text('name')->required()->max(120),
            Field::date('purchased_on'),
            Field::reference('vendor')->to(Vendor::class)->by('vat_id', 'name')->storeAs('vendor_id'),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return $user?->can('manage-assets', $owner) ?? false;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return Asset::create([...$row->attributes(), ...$context->ownerAttributes()]);
    }

    public function update(Model $asset, ImportRow $row, ImportContext $context): Model
    {
        return tap($asset)->update($row->changes());   // blank cells keep the stored value
    }
}
