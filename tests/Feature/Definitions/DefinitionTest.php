<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\FileRules;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Exceptions\InvalidImporterDefinitionException;
use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\Imports\Exceptions\UnknownImporterException;
use RoundlyConsulting\Imports\Exceptions\UnscopedQueryException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Registry\DefinitionValidator;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use Workbench\App\Models\Asset;
use Workbench\App\Models\Team;
use Workbench\App\Models\Vendor;

/**
 * An importer built from closures, for definition rules.
 *
 * @param  array<string, mixed>  $overrides
 * @return class-string<Importer>
 */
function importerWith(array $overrides): string
{
    $importer = new class extends Importer
    {
        /** @var array<string, mixed> */
        public static array $o = [];

        public function key(): string
        {
            return self::$o['key'] ?? 'probe';
        }

        public function label(): string
        {
            return 'Probe';
        }

        public function model(): string
        {
            return self::$o['model'] ?? Asset::class;
        }

        public function ownership(): Ownership
        {
            return isset(self::$o['ownership']) ? (self::$o['ownership'])() : Ownership::column('team_id');
        }

        public function fields(): array
        {
            return self::$o['fields'] ?? [Field::text('name')];
        }

        public function naturalKey(): ?NaturalKey
        {
            return self::$o['naturalKey'] ?? null;
        }

        public function modes(): array
        {
            return self::$o['modes'] ?? parent::modes();
        }

        public function formats(): array
        {
            return self::$o['formats'] ?? parent::formats();
        }

        public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
        {
            return true;
        }

        public function create(ImportRow $row, ImportContext $context): Model
        {
            return new Asset;
        }
    };

    $importer::$o = $overrides;

    return $importer::class;
}

final class TeamMembersImporter extends Importer
{
    public function model(): string
    {
        return Asset::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function fields(): array
    {
        return [
            Field::text('name'),
            Field::text('serial_number'),
            Field::reference('vendor')->to(Vendor::class)->by('name')->storeAs('vendor_id'),
            Field::reference('assignee')->to(Team::class)->by('name')->ownedBy(Ownership::column('id')),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return new Asset;
    }
}

/**
 * @param  array<string, mixed>  $values
 * @param  array<string, string|null>  $raw
 * @param  array<string, Model|null>  $references
 * @param  list<string>  $mapped
 * @param  array<string, string|null>  $storeAs
 */
function importRow(array $values, array $raw, array $references = [], array $mapped = [], array $storeAs = []): ImportRow
{
    return new ImportRow(2, $values, $raw, [], $references, $mapped === [] ? array_keys($raw) : $mapped, $storeAs);
}

function definitionError(array $overrides): string
{
    $registry = new ImporterRegistry(app(), app(DefinitionValidator::class));
    config()->set('imports.importers', []);
    $registry->register(importerWith($overrides));

    try {
        $registry->all();
    } catch (InvalidImporterDefinitionException|UnscopedQueryException $exception) {
        return $exception->getMessage();
    }

    return 'valid';
}

test('a_definition_without_ownership_is_refused', function (): void {
    expect(definitionError(['ownership' => fn () => throw new LogicException('none')]))->toContain('ownership()')
        ->and(definitionError(['ownership' => fn () => Ownership::inherit()]))->toContain('inherit() is only for reference fields');
});

test('update_mode_without_an_update_callback_is_refused', function (): void {
    expect(definitionError(['modes' => [DuplicateMode::Skip, DuplicateMode::Update]]))->toContain('Update mode needs an update() callback');
});

test('a_reference_without_a_scope_is_refused', function (): void {
    // Teams have no team_id column: the importer's rule cannot be inherited by the reference.
    expect(definitionError(['fields' => [Field::text('name'), Field::reference('team')->to(Team::class)->by('name')]]))
        ->toContain('declare ownedBy()');

    expect(definitionError(['fields' => [Field::text('name'), Field::reference('team')->to(Team::class)->by('name')->ownedBy(Ownership::global())]]))->toBe('valid');
});

test('presence_rules_that_bypass_the_owner_scope_are_refused', function (string|object $rule): void {
    expect(definitionError(['fields' => [Field::text('name')->rules($rule)]]))->toContain('database presence rule');
})->with([
    'unique string' => ['unique:assets,name'],
    'exists string' => ['string|exists:vendors,name'],
    'unique object' => [Rule::unique('assets', 'name')],
    'exists object' => [Rule::exists('vendors', 'name')],
]);

test('definition_rules_name_the_importer_and_the_rule', function (array $overrides, string $message): void {
    expect(definitionError($overrides))->toContain($message);
})->with([
    'key' => [['key' => 'Bad Key'], 'the key must match'],
    'model' => [['model' => stdClass::class], 'must return an Eloquent model'],
    'no fields' => [['fields' => []], 'at least one field'],
    'field name' => [['fields' => [Field::text('Bad-Name')]], 'must be named'],
    'twice' => [['fields' => [Field::text('name'), Field::text('name')]], 'declared twice'],
    'choice' => [['fields' => [Field::choice('kind')]], 'needs options()'],
    'reference target' => [['fields' => [Field::reference('vendor')]], 'needs to() with a model class'],
    'reference lookup' => [['fields' => [Field::reference('vendor')->to(Vendor::class)]], 'needs by()'],
    'natural key field' => [['naturalKey' => NaturalKey::by('missing')], 'is not a field'],
    'natural key reference' => [['fields' => [Field::reference('vendor')->to(Vendor::class)->by('name')], 'naturalKey' => NaturalKey::by('vendor')], 'cannot be a reference'],
    'default mode' => [['modes' => [DuplicateMode::CreateNew]], 'defaultMode() must be one of modes()'],
    'formats' => [['formats' => []], 'at least one format'],
]);

test('an_importer_on_another_connection_is_refused', function (): void {
    config()->set('database.connections.other', config('database.connections.testing'));
    $model = new class extends Model
    {
        protected $connection = 'other';

        protected $table = 'assets';
    };

    expect(definitionError(['model' => $model::class]))->toContain('must live on the import connection');
});

test('two_importers_cannot_share_a_key', function (): void {
    Imports::register(importerWith(['key' => 'assets']));

    expect(fn () => Imports::importers()->all())->toThrow(InvalidImporterDefinitionException::class, 'two importers share this key');
});

test('only_importer_classes_can_be_registered_or_configured', function (): void {
    expect(fn () => Imports::register(stdClass::class))->toThrow(InvalidImporterDefinitionException::class);

    config()->set('imports.importers', [stdClass::class]);
    expect(fn () => app(ImporterRegistry::class)->flush() ?? Imports::importers()->all())->toThrow(InvalidImportsConfigurationException::class);
});

test('importers_are_discoverable_for_a_ui', function (): void {
    $descriptor = Imports::importers()->describe('assets', 'sk');
    $status = collect($descriptor->fields)->firstWhere('name', 'status');
    $price = collect($descriptor->fields)->firstWhere('name', 'price');

    expect($descriptor->label)->toBe('Majetok')
        ->and($descriptor->kind)->toBe(ImportKind::Rows)
        ->and($descriptor->modes)->toBe([DuplicateMode::Skip, DuplicateMode::Update, DuplicateMode::CreateNew])
        ->and($status->label)->toBe('Stav')
        ->and($status->type)->toBe(FieldType::Choice)
        ->and(array_map(static fn ($o): string => $o->label, $status->options))->toBe(['Aktívny', 'Vyradený', 'V oprave'])
        ->and($price->format)->toBe('0.00')
        ->and(collect($descriptor->fields)->firstWhere('name', 'asset_tag')->aliases)->toBe(['tag', 'inventory no'])
        ->and(Imports::importers()->describe('documents')->extensions)->toBe(['pdf', 'docx', 'txt', 'zip'])
        ->and(Imports::importers()->has('assets'))->toBeTrue()
        ->and(Imports::importers()->has('nope'))->toBeFalse()
        ->and(Imports::importer('people')->modes())->toBe([DuplicateMode::Skip, DuplicateMode::Update, DuplicateMode::CreateNew])
        ->and(Imports::importer('documents')->modes())->toBe([])
        ->and(Imports::importer('assets')->descriptor()->key)->toBe('assets')
        ->and(fn () => Imports::importer('nope'))->toThrow(UnknownImporterException::class);
});

test('available_importers_are_filtered_by_authorize', function (): void {
    $team = team();
    $other = team();
    $user = admin($team);

    expect(array_map(static fn ($d): string => $d->key, Imports::importers()->available($user, $team)))->toBe(['assets', 'people', 'documents'])
        ->and(Imports::importers()->available($user, $other))->toBe([])
        ->and(Imports::importers()->available(null, $team))->toBe([]);
});

test('fields_are_immutable_and_fluent', function (): void {
    $base = Field::text('code');
    $changed = $base->required()->label(['en' => 'Code', 'sk' => 'Kód'])->help('help')->aliases(['sk' => ['kód']])->max(5)->min(1)->excludeFromTemplate();

    expect($base->required)->toBeFalse()
        ->and($changed->required)->toBeTrue()
        ->and($changed->labelIn('sk'))->toBe('Kód')
        ->and($changed->helpIn('en'))->toBe('help')
        ->and($changed->aliasesIn('sk'))->toBe(['kód'])
        ->and($changed->inTemplate)->toBeFalse()
        ->and($changed->nullable()->required)->toBeFalse()
        ->and(Field::choice('s')->options(FieldType::class)->options)->toHaveKey('text')
        ->and(FileRules::any()->allows('exe'))->toBeTrue()
        ->and(FileRules::extensions('.PDF')->allows('pdf'))->toBeTrue();
});

test('an_importer_without_key_and_label_uses_its_class_name', function (): void {
    Imports::register(TeamMembersImporter::class);

    expect((new TeamMembersImporter)->key())->toBe('team_members')
        ->and((new TeamMembersImporter)->labelIn('en'))->toBe('Team Members')
        ->and(Imports::importers()->describe('team_members')->label)->toBe('Team Members')
        ->and(Imports::importers()->describe('people')->label)->toBe('People');
});

test('a_class_name_that_yields_no_valid_key_is_refused_naming_the_class', function (): void {
    $anonymous = new class extends Importer
    {
        public function model(): string
        {
            return Asset::class;
        }

        public function ownership(): Ownership
        {
            return Ownership::column('team_id');
        }

        public function fields(): array
        {
            return [Field::text('name')];
        }

        public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
        {
            return true;
        }

        public function create(ImportRow $row, ImportContext $context): Model
        {
            return new Asset;
        }
    };
    $registry = new ImporterRegistry(app(), app(DefinitionValidator::class));
    config()->set('imports.importers', []);
    $registry->register($anonymous::class);

    expect(fn () => $registry->all())->toThrow(InvalidImporterDefinitionException::class, 'declare key() when its class name gives none');
});

test('attributes_replaces_references_by_their_stored_key', function (): void {
    $vendor = (new Vendor)->forceFill(['id' => 5]);
    $row = importRow(['name' => 'Laptop', 'vendor' => 'Lenovo', 'assignee' => 'x'], ['name' => 'Laptop', 'vendor' => 'Lenovo', 'assignee' => 'x'], ['vendor' => $vendor, 'assignee' => null], storeAs: ['vendor' => 'vendor_id', 'assignee' => null]);

    expect($row->attributes())->toBe(['name' => 'Laptop', 'vendor_id' => 5]);
});

test('a_reference_without_store_as_is_not_in_attributes', function (): void {
    $row = importRow(['name' => 'Laptop', 'assignee' => 'x'], ['name' => 'Laptop', 'assignee' => 'x'], ['assignee' => (new Team)->forceFill(['id' => 3])], storeAs: ['assignee' => null]);

    expect($row->attributes())->toBe(['name' => 'Laptop'])->and($row->changes())->toBe(['name' => 'Laptop']);
});

test('changes_excludes_unmapped_and_blank_fields', function (): void {
    $row = importRow(
        ['name' => 'Laptop', 'serial_number' => null, 'status' => 'active', 'vendor' => null],
        ['name' => 'Laptop', 'serial_number' => '  ', 'vendor' => ''],
        ['vendor' => null],
        ['name', 'serial_number', 'vendor'],
        ['vendor' => 'vendor_id'],
    );

    expect($row->attributes())->toBe(['name' => 'Laptop', 'serial_number' => null, 'status' => 'active', 'vendor_id' => null])
        ->and($row->changes())->toBe(['name' => 'Laptop'])
        ->and($row->filledIn('name'))->toBeTrue()
        ->and($row->filledIn('serial_number'))->toBeFalse()
        ->and($row->filledIn('status'))->toBeFalse();
});

test('store_as_naming_another_field_is_refused', function (): void {
    $vendor = Field::reference('vendor')->to(Vendor::class)->by('name');

    expect(definitionError(['fields' => [Field::text('name'), $vendor->storeAs('name')]]))->toContain('which is a field')
        ->and(definitionError(['fields' => [Field::text('name'), $vendor->storeAs('vendor id')]]))->toContain('not a column name')
        ->and(definitionError(['fields' => [Field::text('name')->storeAs('x')]]))->toContain('storeAs() is for reference fields')
        ->and(definitionError(['fields' => [Field::text('name'), $vendor->storeAs('vendor_id')]]))->toBe('valid');
});
