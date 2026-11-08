<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Exceptions\InvalidImporterDefinitionException;
use RoundlyConsulting\Imports\Exceptions\UnscopedQueryException;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use Throwable;

/**
 * Validates each importer once per process, naming the importer and the rule it breaks.
 *
 * @internal
 */
final class DefinitionValidator
{
    public function validate(ImportDefinition $definition): void
    {
        $key = $definition->key();

        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $key) !== 1) {
            throw InvalidImporterDefinitionException::because($key, 'the key must match ^[a-z][a-z0-9_.-]{0,63}$.');
        }

        try {
            $definition->ownership();
        } catch (Throwable $exception) {
            throw InvalidImporterDefinitionException::because($key, 'ownership() must declare how records belong to the owner ('.$exception->getMessage().').');
        }

        if ($definition->ownership()->kind === 'inherit') {
            throw InvalidImporterDefinitionException::because($key, 'ownership() must declare a rule; inherit() is only for reference fields.');
        }

        if ($definition instanceof Importer) {
            $this->rows($definition);
        }

        if ($definition instanceof FileImporter) {
            $definition->accepts();
        }
    }

    private function rows(Importer $importer): void
    {
        $key = $importer->key();
        $model = $importer->model();

        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw InvalidImporterDefinitionException::because($key, 'model() must return an Eloquent model class.');
        }

        $connection = (new $model)->getConnectionName() ?? config('database.default');
        $packageConnection = (new (ImportModels::session()))->getConnectionName() ?? config('database.default');

        if ($connection !== $packageConnection) {
            throw InvalidImporterDefinitionException::because($key, sprintf('model() %s must live on the import connection [%s]: its write and its ledger row commit in one transaction.', class_basename($model), is_string($packageConnection) ? $packageConnection : 'default'));
        }

        $fields = $importer->fields();

        if ($fields === []) {
            throw InvalidImporterDefinitionException::because($key, 'fields() must declare at least one field.');
        }

        if (count($fields) > ImportsConfig::MAX_COLUMNS_CEILING) {
            throw InvalidImporterDefinitionException::because($key, 'fields() declares more fields than a sheet has columns.');
        }

        $names = [];

        foreach ($fields as $field) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $field->name) !== 1) {
                throw InvalidImporterDefinitionException::because($key, sprintf('field [%s] must be named ^[a-z][a-z0-9_]*$.', $field->name));
            }

            if (isset($names[$field->name])) {
                throw InvalidImporterDefinitionException::because($key, sprintf('field [%s] is declared twice.', $field->name));
            }

            $names[$field->name] = $field;

            if ($field->type === FieldType::Choice && $field->options === []) {
                throw InvalidImporterDefinitionException::because($key, sprintf('choice field [%s] needs options().', $field->name));
            }

            if ($field->type === FieldType::Reference) {
                $this->reference($importer, $field->name, $field->target, $field->lookup, $field->ownership->kind);
            }

            foreach ($field->rules as $rule) {
                if ($rule instanceof Exists || $rule instanceof Unique
                    || (is_string($rule) && preg_match('/^(exists|unique)(:|$)/i', trim($rule)) === 1)) {
                    throw InvalidImporterDefinitionException::because($key, sprintf('field [%s] uses a database presence rule (exists/unique): it bypasses the owner scope and can reveal another owner\'s data. Use Field::reference() or naturalKey() instead.', $field->name));
                }
            }
        }

        foreach ($importer->naturalKey()->candidates ?? [] as $candidate) {
            foreach ($candidate as $name) {
                if (! isset($names[$name])) {
                    throw InvalidImporterDefinitionException::because($key, sprintf('natural key field [%s] is not a field.', $name));
                }

                if ($names[$name]->type === FieldType::Reference) {
                    throw InvalidImporterDefinitionException::because($key, sprintf('natural key field [%s] cannot be a reference.', $name));
                }
            }
        }

        $modes = $importer->modes();

        if (in_array(DuplicateMode::Update, $modes, true) && ! $importer->supportsUpdate()) {
            throw InvalidImporterDefinitionException::because($key, 'the Update mode needs an update() callback.');
        }

        if ($modes === [] || ! in_array($importer->defaultMode(), $modes, true)) {
            throw InvalidImporterDefinitionException::because($key, 'defaultMode() must be one of modes().');
        }

        if ($importer->formats() === []) {
            throw InvalidImporterDefinitionException::because($key, 'formats() must allow at least one format.');
        }
    }

    /**
     * @param  class-string<Model>|null  $target
     * @param  list<string>  $lookup
     */
    private function reference(Importer $importer, string $field, ?string $target, array $lookup, string $ownership): void
    {
        $key = $importer->key();

        if ($target === null || ! class_exists($target) || ! is_subclass_of($target, Model::class)) {
            throw InvalidImporterDefinitionException::because($key, sprintf('reference field [%s] needs to() with a model class.', $field));
        }

        if ($lookup === []) {
            throw InvalidImporterDefinitionException::because($key, sprintf('reference field [%s] needs by() with at least one column.', $field));
        }

        if ($ownership !== 'inherit') {
            return;
        }

        // Inherit = the importer's rule applied to the referenced model: it must make sense there.
        $rule = $importer->ownership();
        $model = new $target;

        $ok = match ($rule->kind) {
            'column' => Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), (string) $rule->name),
            'morph' => Schema::connection($model->getConnectionName())->hasColumns($model->getTable(), [$rule->name.'_type', $rule->name.'_id']),
            'belongsTo' => method_exists($model, (string) $rule->name),
            default => true,
        };

        if (! $ok) {
            throw UnscopedQueryException::forModel($key, $target, sprintf('reference field [%s] inherits %s, which %s does not have; declare ownedBy()', $field, $rule->describe(), class_basename($target)));
        }
    }
}
