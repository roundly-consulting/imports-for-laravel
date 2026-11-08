<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Registry;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\DataTransferObjects\FieldDescriptor;
use RoundlyConsulting\Imports\DataTransferObjects\ImporterDescriptor;
use RoundlyConsulting\Imports\DataTransferObjects\OptionDescriptor;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Exceptions\InvalidImporterDefinitionException;
use RoundlyConsulting\Imports\Exceptions\UnknownImporterException;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;

/**
 * The importers of the app: `config('imports.importers')` plus `Imports::register()`, resolved
 * through the container and validated lazily on first use (host registrations in
 * `AppServiceProvider::boot()` run after the package booted). Workers resolve by key — jobs carry
 * ids only.
 *
 * @internal
 */
final class ImporterRegistry
{
    /** @var list<class-string<ImportDefinition>> */
    private array $registered = [];

    /** @var array<string, ImportDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly Container $container,
        private readonly DefinitionValidator $validator,
    ) {}

    /**
     * @param  class-string<ImportDefinition>  ...$classes
     */
    public function register(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (! class_exists($class) || (! is_subclass_of($class, Importer::class) && ! is_subclass_of($class, FileImporter::class))) {
                throw InvalidImporterDefinitionException::because($class, 'it must extend Importer or FileImporter.');
            }

            $this->registered[] = $class;
        }

        $this->definitions = null;
    }

    /**
     * @return list<ImportDefinition>
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function has(string $key): bool
    {
        return isset($this->load()[$key]);
    }

    public function find(string $key): ImportDefinition
    {
        return $this->load()[$key] ?? throw UnknownImporterException::key($key);
    }

    public function rows(string $key): Importer
    {
        $definition = $this->find($key);

        return $definition instanceof Importer ? $definition : throw UnknownImporterException::key($key);
    }

    public function files(string $key): FileImporter
    {
        $definition = $this->find($key);

        return $definition instanceof FileImporter ? $definition : throw UnknownImporterException::key($key);
    }

    /**
     * The importers `$user` may view for `$owner`.
     *
     * @return list<ImportDefinition>
     */
    public function available(?Authenticatable $user, Model $owner): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ImportDefinition $definition): bool => $definition->authorize($user, $owner, ImportOperation::View),
        ));
    }

    public function describe(string $key, ?string $locale = null): ImporterDescriptor
    {
        return self::descriptor($this->find($key), $locale);
    }

    public static function descriptor(ImportDefinition $definition, ?string $locale = null): ImporterDescriptor
    {
        $locale ??= Locales::current();

        if ($definition instanceof FileImporter) {
            return new ImporterDescriptor($definition->key(), $definition->labelIn($locale), $definition->kind(), [], [], null, [], $definition->accepts()->extensions);
        }

        $fields = [];

        if ($definition instanceof Importer) {
            foreach ($definition->fields() as $field) {
                $options = [];

                foreach (array_keys($field->options) as $value) {
                    $options[] = new OptionDescriptor((string) $value, $field->optionLabel((string) $value, $locale));
                }

                $example = $field->exampleValue();

                $fields[] = new FieldDescriptor(
                    name: $field->name,
                    label: $field->labelIn($locale),
                    type: $field->type,
                    required: $field->required,
                    options: $options,
                    example: is_scalar($example) ? (string) $example : '',
                    help: $field->helpIn($locale),
                    aliases: $field->aliasesIn($locale),
                    format: match ($field->type) {
                        FieldType::Date => 'yyyy-mm-dd',
                        FieldType::DateTime => 'yyyy-mm-dd hh:mm',
                        FieldType::Decimal => '0.'.str_repeat('0', $field->scale),
                        default => null,
                    },
                    inTemplate: $field->inTemplate,
                );
            }

            return new ImporterDescriptor($definition->key(), $definition->labelIn($locale), $definition->kind(), $fields, $definition->modes(), $definition->defaultMode(), $definition->formats());
        }

        return new ImporterDescriptor($definition->key(), $definition->labelIn($locale), $definition->kind(), [], [], null, []);
    }

    /**
     * Forget the resolved definitions (tests, Octane).
     */
    public function flush(): void
    {
        $this->definitions = null;
    }

    /**
     * @return array<string, ImportDefinition>
     */
    private function load(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach (array_values(array_unique([...ImportsConfig::importers(), ...$this->registered])) as $class) {
            $definition = $this->container->make($class);

            if (! $definition instanceof ImportDefinition) {
                throw InvalidImporterDefinitionException::because($class, 'it must extend Importer or FileImporter.');
            }

            $this->validator->validate($definition);

            if (isset($definitions[$definition->key()])) {
                throw InvalidImporterDefinitionException::because($definition->key(), 'two importers share this key.');
            }

            $definitions[$definition->key()] = $definition;
        }

        return $this->definitions = $definitions;
    }
}
