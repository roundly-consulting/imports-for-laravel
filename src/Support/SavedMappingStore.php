<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;
use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\Imports\Exceptions\TooManySavedMappingsException;
use RoundlyConsulting\Imports\Options\SavedMappings;
use RoundlyConsulting\Options\OptionsManager;

/**
 * Saved mappings live in one options row per owner, `{importer: {name: mapping}}`. Writes are a
 * read-modify-write under a cache lock (options has no atomic update), bounded so the serialized
 * value always fits the options `text` column (64 KB on MySQL).
 *
 * @internal
 */
final readonly class SavedMappingStore
{
    public function __construct(
        private OptionsManager $options,
        private CacheFactory $cache,
    ) {}

    /**
     * @return list<SavedMapping>
     */
    public function all(Model $owner, string $importer): array
    {
        $mappings = [];

        foreach ($this->read($owner)[$importer] ?? [] as $name => $mapping) {
            $parsed = self::parse((string) $name, $importer, $mapping);

            if ($parsed !== null) {
                $mappings[] = $parsed;
            }
        }

        return $mappings;
    }

    public function find(Model $owner, string $importer, string $name): ?SavedMapping
    {
        foreach ($this->all($owner, $importer) as $mapping) {
            if ($mapping->name === $name) {
                return $mapping;
            }
        }

        return null;
    }

    public function bySignature(Model $owner, string $importer, string $signature): ?SavedMapping
    {
        $match = null;

        foreach ($this->all($owner, $importer) as $mapping) {
            if ($mapping->signature === $signature && ($match === null || (string) $mapping->updatedAt > (string) $match->updatedAt)) {
                $match = $mapping;
            }
        }

        return $match;
    }

    public function save(Model $owner, SavedMapping $mapping): void
    {
        $name = $mapping->name;

        $this->locked($owner, function (array $all) use ($mapping, $name): array {
            $existing = $all[$mapping->importer] ?? [];

            if (! isset($existing[$name]) && count($existing) >= ImportsConfig::mappingsMaxPerImporter()) {
                throw TooManySavedMappingsException::limit(ImportsConfig::mappingsMaxPerImporter());
            }

            $existing[$name] = [
                'columns' => $mapping->columns,
                'signature' => $mapping->signature,
                'delimiter' => $mapping->delimiter,
                'sheet' => $mapping->sheet,
                'header_row' => $mapping->headerRow,
                'updated_at' => Clock::now()->toIso8601String(),
            ];

            $all[$mapping->importer] = $existing;

            if (strlen((string) json_encode($all)) > ImportsConfig::mappingsMaxBytes()) {
                throw TooManySavedMappingsException::tooLarge();
            }

            return $all;
        });
    }

    public function delete(Model $owner, string $importer, string $name): bool
    {
        $deleted = false;

        $this->locked($owner, function (array $all) use ($importer, $name, &$deleted): array {
            if (isset($all[$importer][$name])) {
                unset($all[$importer][$name]);
                $deleted = true;
            }

            if (($all[$importer] ?? null) === []) {
                unset($all[$importer]);
            }

            return $all;
        });

        return $deleted;
    }

    /**
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $mutate
     */
    private function locked(Model $owner, callable $mutate): void
    {
        $store = $this->cache->store(ImportsConfig::lockStore())->getStore();

        if (! $store instanceof LockProvider) {
            throw InvalidImportsConfigurationException::invalidValue('imports.mappings.lock_store', 'a cache store that supports locks');
        }

        $store->lock('imports:mappings:'.Digests::owner($owner), 10)->block(5, function () use ($owner, $mutate): void {
            $this->options->withoutAuthorization(function () use ($owner, $mutate): void {
                $this->options->resolveOptionInstance(SavedMappings::class, $owner)->forgetCachedValue();
                $all = $mutate($this->read($owner));
                self::explainingAMissingTable(function () use ($all, $owner): bool {
                    $this->options->set(SavedMappings::class, $all, $owner);

                    return true;
                });
            });
        });
    }

    /**
     * A host that skipped options' publish-only migration gets the fix, not a raw SQL error. The
     * table check runs only once a query failed, never on the happy path.
     *
     * @template T
     *
     * @param  callable(): T  $query
     * @return T
     */
    private static function explainingAMissingTable(callable $query): mixed
    {
        try {
            return $query();
        } catch (QueryException $exception) {
            if (! OptionsTable::exists()) {
                throw InvalidImportsConfigurationException::missingTable(OptionsTable::name(), 'php artisan vendor:publish --tag=options-migrations');
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(Model $owner): array
    {
        $value = self::explainingAMissingTable(fn (): mixed => $this->options->withoutAuthorization(fn (): mixed => $this->options->get(SavedMappings::class, $owner)));
        $result = [];

        if (is_array($value)) {
            foreach ($value as $importer => $mappings) {
                if (is_string($importer) && is_array($mappings)) {
                    /** @var array<string, mixed> $mappings */
                    $result[$importer] = $mappings;
                }
            }
        }

        return $result;
    }

    private static function parse(string $name, string $importer, mixed $mapping): ?SavedMapping
    {
        if (! is_array($mapping) || ! is_array($mapping['columns'] ?? null) || ! is_string($mapping['signature'] ?? null)) {
            return null;
        }

        $columns = [];

        foreach ($mapping['columns'] as $header => $field) {
            if (is_string($field)) {
                $columns[(string) $header] = $field;
            }
        }

        return new SavedMapping(
            name: $name,
            importer: $importer,
            columns: $columns,
            signature: $mapping['signature'],
            delimiter: is_string($mapping['delimiter'] ?? null) ? $mapping['delimiter'] : null,
            sheet: is_string($mapping['sheet'] ?? null) ? $mapping['sheet'] : null,
            headerRow: is_int($mapping['header_row'] ?? null) ? $mapping['header_row'] : 1,
            updatedAt: is_string($mapping['updated_at'] ?? null) ? $mapping['updated_at'] : null,
        );
    }
}
