<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionMethod;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\FieldChange;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Definition\Limits;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;

/**
 * One importer per record type: list it in `config('imports.importers')` (or
 * `Imports::register()`). Define its fields, how its records belong to the owner, who may import,
 * and how a row becomes a record:
 *
 * ```php
 * final class AssetsImporter extends Importer
 * {
 *     public function key(): string { return 'assets'; }
 *     public function label(): string { return 'app.imports.assets'; }
 *     public function model(): string { return Asset::class; }
 *     public function ownership(): Ownership { return Ownership::column('team_id'); }
 *     public function fields(): array { return [Field::text('name')->required()]; }
 *     public function authorize(?Authenticatable $user, Model $owner, ImportOperation $op): bool { … }
 *     public function create(ImportRow $row, ImportContext $context): Model { … }
 * }
 * ```
 */
abstract class Importer extends ImportDefinition
{
    /**
     * The target model; duplicate matching starts from it. It must live on the same connection
     * as the package's tables (the write and its ledger row commit in one transaction).
     *
     * @return class-string<Model>
     */
    abstract public function model(): string;

    /**
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * Turn a validated row into a saved record (on the import connection, inside the owner).
     * Returning an existing record (e.g. `firstOrCreate`) is fine: it is ledgered as linked and
     * never removed by undo.
     */
    abstract public function create(ImportRow $row, ImportContext $context): Model;

    /**
     * Update a matched record — required when the importer offers the Update mode.
     */
    public function update(Model $record, ImportRow $row, ImportContext $context): Model
    {
        throw new LogicException(sprintf('Importer [%s] does not support updates.', $this->key()));
    }

    /**
     * How a row finds the existing record it duplicates; null = no duplicate detection.
     */
    public function naturalKey(): ?NaturalKey
    {
        return null;
    }

    /**
     * @return list<DuplicateMode>
     */
    public function modes(): array
    {
        return $this->supportsUpdate()
            ? [DuplicateMode::Skip, DuplicateMode::Update, DuplicateMode::CreateNew]
            : [DuplicateMode::Skip, DuplicateMode::CreateNew];
    }

    public function defaultMode(): DuplicateMode
    {
        return DuplicateMode::Skip;
    }

    /**
     * What an update would change, for the preview: row fields against the same-named attributes.
     *
     * @return list<FieldChange>
     */
    public function diff(Model $record, ImportRow $row): array
    {
        $changes = [];

        foreach ($this->fields() as $field) {
            if (! $row->has($field->name) || $field->type === FieldType::Reference) {
                continue;
            }

            $to = $row->get($field->name);
            $from = $record->getAttribute($field->name);

            if (FieldChange::comparable($from) !== FieldChange::comparable($to)) {
                $changes[] = new FieldChange($field->name, FieldChange::comparable($from), FieldChange::comparable($to));
            }
        }

        return $changes;
    }

    /**
     * @return list<SourceFormat>
     */
    public function formats(): array
    {
        return [SourceFormat::Csv, SourceFormat::Xlsx];
    }

    public function limits(): Limits
    {
        return Limits::fromConfig();
    }

    final public function kind(): ImportKind
    {
        return ImportKind::Rows;
    }

    final public function supportsUpdate(): bool
    {
        return (new ReflectionMethod($this, 'update'))->getDeclaringClass()->getName() !== self::class;
    }

    final public function field(string $name): ?Field
    {
        foreach ($this->fields() as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
