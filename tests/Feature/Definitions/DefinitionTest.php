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
