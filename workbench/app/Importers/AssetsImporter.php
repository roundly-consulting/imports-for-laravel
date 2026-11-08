<?php

declare(strict_types=1);

namespace Workbench\App\Importers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use Workbench\App\Hooks;
use Workbench\App\Models\Asset;
use Workbench\App\Models\User;
use Workbench\App\Models\Vendor;

/**
 * The README's importer, plus an assignee looked up among the team's members by email.
 */
final class AssetsImporter extends Importer
{
    public function key(): string
    {
        return 'assets';
    }

    public function label(): array
    {
        return ['en' => 'Assets', 'sk' => 'Majetok'];
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
        return NaturalKey::by('asset_tag')->then('serial_number');
    }

    public function fields(): array
    {
        return [
            Field::text('asset_tag')->label(['en' => 'Asset tag', 'sk' => 'Inventárne číslo'])->aliases('tag', 'inventory no')->example('AST-0042'),
            Field::text('serial_number')->label(['en' => 'Serial number', 'sk' => 'Sériové číslo'])->example('SN-99812'),
            Field::text('name')->label(['en' => 'Name', 'sk' => 'Názov'])->required()->max(120)->example('ThinkPad T14'),
            Field::choice('status')->label(['en' => 'Status', 'sk' => 'Stav'])->options([
                'active' => ['en' => 'Active', 'sk' => 'Aktívny'],
                'retired' => ['en' => 'Retired', 'sk' => 'Vyradený'],
                'repair' => ['en' => 'In repair', 'sk' => 'V oprave'],
            ])->default('active'),
            Field::date('purchased_on')->label(['en' => 'Purchased on', 'sk' => 'Dátum nákupu'])->example('2026-01-15'),
            Field::decimal('price', 2)->label(['en' => 'Price', 'sk' => 'Cena'])->example('1299.00'),
            Field::boolean('insured')->label(['en' => 'Insured', 'sk' => 'Poistený']),
            Field::reference('vendor')->label(['en' => 'Vendor', 'sk' => 'Dodávateľ'])->to(Vendor::class)->by('vat_id', 'name'),
            Field::reference('assignee')->label(['en' => 'Assignee', 'sk' => 'Používateľ'])->to(User::class)->by('email')->caseInsensitive()
                ->ownedBy(Ownership::using(
                    query: static fn (Builder $query, Model $team): Builder => $query->where('team_id', $team->getKey()),
                    owns: static fn (Model $user, Model $team): bool => $user->getAttribute('team_id') === $team->getKey(),
                )),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return $user instanceof User && $user->is_admin && $user->team_id === $owner->getKey();
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        Hooks::call('create', $row->number());

        return Asset::create([
            ...$row->except('vendor', 'assignee'),
            'vendor_id' => $row->reference('vendor')?->getKey(),
            'assignee_id' => $row->reference('assignee')?->getKey(),
            ...$context->ownerAttributes(),
        ]);
    }

    public function update(Model $record, ImportRow $row, ImportContext $context): Model
    {
        Hooks::call('update', $row->number());

        $record->fill([
            ...$row->except('vendor', 'assignee'),
            'vendor_id' => $row->reference('vendor')?->getKey() ?? $record->getAttribute('vendor_id'),
        ])->save();

        return $record;
    }
}
