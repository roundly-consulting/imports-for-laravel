<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Carbon\CarbonInterval;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Contracts\ResolvesImportOwner;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Http\Resources\ImportActorResource;
use RoundlyConsulting\Imports\Http\Resources\ImportOwnerResource;
use RoundlyConsulting\Imports\Http\Resources\ImportRecordResource;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The only place that reads `imports.*`. Every reader is strict: a typo throws naming the key, a
 * blank value takes the shipped default.
 *
 * @internal
 */
final class ImportsConfig
{
    public const int MAX_ROWS_CEILING = 100_000;

    public const int MAX_COLUMNS_CEILING = 16_384;

    // Key types ----------------------------------------------------------------------------

    public static function keyType(): KeyType
    {
        return KeyType::fromConfig('imports.key_type');
    }

    public static function recordKeyType(): KeyType
    {
        return self::blank(config('imports.record_key_type'))
            ? self::keyType()
            : KeyType::fromConfig('imports.record_key_type');
    }

    public static function primaryKeyType(): KeyType
    {
        return KeyType::fromConfig('imports.primary_key_type');
    }

    /**
     * The config key that governs a morph role's key type.
     */
    public static function keyTypeKeyFor(string $role): string
    {
        return $role === 'record' && ! self::blank(config('imports.record_key_type'))
            ? 'imports.record_key_type'
            : 'imports.key_type';
    }

    // Tables --------------------------------------------------------------------------------

    public static function table(string $name): string
    {
        [$key, $table] = match ($name) {
            'sessions' => ['imports.tables.sessions', Config::string('imports.tables.sessions', 'import_sessions')],
            'batches' => ['imports.tables.batches', Config::string('imports.tables.batches', 'import_batches')],
            'row_results' => ['imports.tables.row_results', Config::string('imports.tables.row_results', 'import_row_results')],
            'records' => ['imports.tables.records', Config::string('imports.tables.records', 'imported_records')],
            default => throw InvalidImportsConfigurationException::invalidTable('imports.tables.'.$name, $name),
        };

        // Index names are derived from the table name: keep them under MySQL's 64 characters.
        if (preg_match('/^[a-z_][a-z0-9_]{0,29}$/', $table) !== 1) {
            throw InvalidImportsConfigurationException::invalidTable($key, $table);
        }

        return $table;
    }

    // Importers / scope ---------------------------------------------------------------------

    /**
     * @return list<class-string<Importer|FileImporter>>
     */
    public static function importers(): array
    {
        $classes = Config::list('imports.importers', []);
        $result = [];

        foreach ($classes as $class) {
            if (! class_exists($class) || (! is_subclass_of($class, Importer::class) && ! is_subclass_of($class, FileImporter::class))) {
                throw InvalidImportsConfigurationException::notAnImplementation('imports.importers', Importer::class, $class);
            }

            $result[] = $class;
        }

        return $result;
    }

    /**
     * @return class-string<OwnerScope>
     */
    public static function ownerScope(): string
    {
        $class = Config::string('imports.owner_scope', ExplicitOwnerScope::class);

        if (! class_exists($class) || ! is_subclass_of($class, OwnerScope::class)) {
            throw InvalidImportsConfigurationException::notAnImplementation('imports.owner_scope', OwnerScope::class, $class);
        }

        return $class;
    }

    /**
     * @return class-string<ResolvesImportOwner>|null
     */
    public static function ownerResolver(): ?string
    {
        $class = config('imports.routes.owner_resolver');

        if (self::blank($class)) {
            return null;
        }

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, ResolvesImportOwner::class)) {
            throw InvalidImportsConfigurationException::notAnImplementation('imports.routes.owner_resolver', ResolvesImportOwner::class, $class);
        }

        return $class;
    }

    // Storage -------------------------------------------------------------------------------

    public static function disk(): string
    {
        return Config::string('imports.storage.disk', 'local');
    }

    public static function storagePath(): string
    {
        return trim(Config::string('imports.storage.path', 'imports'), '/');
    }

    public static function retention(): CarbonInterval
    {
        return self::duration('imports.storage.retention', config('imports.storage.retention'), 'P30D');
    }

    public static function tmpPath(): string
    {
        $path = config('imports.storage.tmp_path');

        if (self::blank($path)) {
            return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'roundly-imports';
        }

        if (! is_string($path)) {
            throw InvalidImportsConfigurationException::invalidValue('imports.storage.tmp_path', 'a directory path');
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    public static function tmpTtl(): CarbonInterval
    {
        return self::duration('imports.storage.tmp_ttl', config('imports.storage.tmp_ttl'), 'PT6H');
    }

    public static function historyRetention(): ?CarbonInterval
    {
        $value = config('imports.history.retention');

        return self::blank($value) ? null : self::duration('imports.history.retention', $value, 'P365D');
    }

    // Limits --------------------------------------------------------------------------------

    public static function maxFileSizeKb(): int
    {
        return Config::integer('imports.limits.file_size', 10240, min: 1, max: 2_097_152);
    }

    public static function maxRows(): int
    {
        return Config::integer('imports.limits.rows', 10000, min: 1, max: self::MAX_ROWS_CEILING);
    }

    public static function maxColumns(): int
    {
        return Config::integer('imports.limits.columns', 100, min: 1, max: self::MAX_COLUMNS_CEILING);
    }

    public static function cellLength(): int
    {
        return Config::integer('imports.limits.cell_length', 32767, min: 1, max: 1_048_576);
    }

    // CSV / XLSX ----------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function csvDelimiters(): array
    {
        $delimiters = config('imports.csv.delimiters');

        if (self::blank($delimiters)) {
            return [',', ';'];
        }

        if (! is_array($delimiters) || $delimiters === []) {
            throw InvalidImportsConfigurationException::invalidValue('imports.csv.delimiters', 'a list of one-character delimiters');
        }

        $result = [];

        foreach ($delimiters as $delimiter) {
            if (! is_string($delimiter) || strlen($delimiter) !== 1 || $delimiter === '"') {
                throw InvalidImportsConfigurationException::invalidValue('imports.csv.delimiters', 'a list of one-character delimiters');
            }

            $result[] = $delimiter;
        }

        return $result;
    }

    public static function sniffBytes(): int
    {
        return Config::integer('imports.csv.sniff_bytes', 65536, min: 1024, max: 1_048_576);
    }

    public static function unescapeFormulaPrefix(): bool
    {
        return Config::boolean('imports.csv.unescape_formula_prefix', true);
    }

    public static function xlsxMaxEntries(): int
    {
        return Config::integer('imports.xlsx.max_entries', 1000, min: 1, max: 100_000);
    }

    public static function xlsxMaxPartSize(): int
    {
        return Config::integer('imports.xlsx.max_part_size', 268_435_456, min: 1024);
    }

    public static function xlsxMaxInflated(): int
    {
        return Config::integer('imports.xlsx.max_inflated', 536_870_912, min: 1024);
    }

    public static function xlsxMaxBlankRows(): int
    {
        return Config::integer('imports.xlsx.max_blank_rows', 10000, min: 1);
    }

    public static function sharedStringsMemory(): int
    {
        return Config::integer('imports.xlsx.shared_strings_memory', 2_097_152, min: 1024);
    }

    public static function allowMacroEnabled(): bool
    {
        return Config::boolean('imports.xlsx.allow_macro_enabled', false);
    }

    // Headers / values ----------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function headerLocales(): array
    {
        return Config::list('imports.headers.locales', ['en', 'sk']);
    }

    public static function fuzzy(): bool
    {
        return Config::boolean('imports.headers.fuzzy', true);
    }

    public static function fuzzyThreshold(): float
    {
        return Config::float('imports.headers.fuzzy_threshold', 0.85, min: 0.5, max: 1.0);
    }

    /**
     * @return list<string>
     */
    public static function dateFormats(): array
    {
        return Config::list('imports.values.date_formats', ['Y-m-d', 'd.m.Y', 'j.n.Y', 'j. n. Y']);
    }

    /**
     * @return list<string>
     */
    public static function datetimeFormats(): array
    {
        return Config::list('imports.values.datetime_formats', ['Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i', 'j.n.Y H:i']);
    }

    public static function timezone(): string
    {
        $timezone = config('imports.values.timezone');

        if (self::blank($timezone)) {
            $app = config('app.timezone');

            return is_string($app) && $app !== '' ? $app : 'UTC';
        }

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true) && $timezone !== 'UTC') {
            throw InvalidImportsConfigurationException::invalidValue('imports.values.timezone', 'a timezone identifier');
        }

        return $timezone;
    }

    // Preview / commit ----------------------------------------------------------------------

    public static function syncThreshold(): int
    {
        return Config::integer('imports.preview.sync_threshold', 500, min: 0, max: self::MAX_ROWS_CEILING);
    }

    public static function previewWindow(): int
    {
        return Config::integer('imports.preview.window', 500, min: 1, max: 10_000);
    }

    public static function progressEvery(): int
    {
        return Config::integer('imports.preview.progress_every', 250, min: 1, max: 100_000);
    }

    public static function chunkSize(): int
    {
        return Config::integer('imports.commit.chunk_size', 250, min: 1, max: 10_000);
    }

    public static function maxChunks(): int
    {
        return Config::integer('imports.commit.max_chunks', 100, min: 1, max: 1000);
    }

    public static function tries(): int
    {
        return Config::integer('imports.commit.tries', 3, min: 1, max: 100);
    }

    /**
     * @return list<int>
     */
    public static function backoff(): array
    {
        $backoff = config('imports.commit.backoff');

        if (self::blank($backoff)) {
            return [10, 60, 300];
        }

        $values = is_array($backoff) ? $backoff : [$backoff];
        $result = [];

        foreach ($values as $value) {
            if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                $value = (int) $value;
            }

            if (! is_int($value) || $value < 0) {
                throw InvalidImportsConfigurationException::invalidValue('imports.commit.backoff', 'a list of non-negative seconds');
            }

            $result[] = $value;
        }

        return $result;
    }

    public static function timeout(): int
    {
        return Config::integer('imports.commit.timeout', 600, min: 1, max: 86_400);
    }

    public static function undoWindow(): CarbonInterval
    {
        return self::duration('imports.undo.window', config('imports.undo.window'), 'P7D');
    }

    public static function expireAfter(): CarbonInterval
    {
        return self::duration('imports.sessions.expire_after', config('imports.sessions.expire_after'), 'P2D');
    }

    public static function staleAfter(): CarbonInterval
    {
        return self::duration('imports.sessions.stale_after', config('imports.sessions.stale_after'), 'PT30M');
    }

    public static function abandonAfter(): CarbonInterval
    {
        return self::duration('imports.sessions.abandon_after', config('imports.sessions.abandon_after'), 'PT6H');
    }

    // Queue ---------------------------------------------------------------------------------

    public static function queueEnabled(): bool
    {
        return Config::boolean('imports.queue.enabled', true);
    }

    public static function queueConnection(): ?string
    {
        return self::optionalString('imports.queue.connection', config('imports.queue.connection'));
    }

    public static function queueName(): ?string
    {
        return self::optionalString('imports.queue.queue', config('imports.queue.queue'));
    }

    // Files ---------------------------------------------------------------------------------

    public static function maxFiles(): int
    {
        return Config::integer('imports.files.max_files', 500, min: 1, max: 100_000);
    }

    public static function maxUncompressed(): int
    {
        return Config::integer('imports.files.max_uncompressed', 1_073_741_824, min: 1024);
    }

    public static function maxRatio(): int
    {
        return Config::integer('imports.files.max_ratio', 100, min: 1, max: 100_000);
    }

    public static function ratioMinSize(): int
    {
        return Config::integer('imports.files.ratio_min_size', 1_048_576, min: 0);
    }

    public static function maxFileSizeForFilesKb(): int
    {
        return Config::integer('imports.files.max_file_size', 51200, min: 1);
    }

    public static function maxArchiveSizeKb(): int
    {
        return Config::integer('imports.files.max_archive_size', 1_048_576, min: 1);
    }

    /**
     * @return list<string>
     */
    public static function filesIgnore(): array
    {
        return Config::list('imports.files.ignore', ['__MACOSX/*', '*.DS_Store', 'Thumbs.db', 'desktop.ini']);
    }

    // Templates -----------------------------------------------------------------------------

    public static function templateDefaultFormat(): TemplateFormat
    {
        return Config::enum('imports.templates.default_format', TemplateFormat::class, TemplateFormat::Xlsx);
    }

    public static function templateExampleRow(): bool
    {
        return Config::boolean('imports.templates.example_row', true);
    }

    public static function hintRow(TemplateFormat $format): bool
    {
        return $format === TemplateFormat::Csv
            ? Config::boolean('imports.templates.hint_row.csv', true)
            : Config::boolean('imports.templates.hint_row.xlsx', false);
    }

    public static function dataValidation(): bool
    {
        return Config::boolean('imports.templates.data_validation', true);
    }

    public static function csvTemplateDelimiter(string $locale): string
    {
        $map = config('imports.templates.csv_delimiter');

        if (self::blank($map)) {
            return $locale === 'sk' ? ';' : ',';
        }

        if (! is_array($map)) {
            throw InvalidImportsConfigurationException::invalidValue('imports.templates.csv_delimiter', 'a map of locale => delimiter with a `default` entry');
        }

        $delimiter = $map[$locale] ?? $map['default'] ?? ',';

        if (! is_string($delimiter) || strlen($delimiter) !== 1) {
            throw InvalidImportsConfigurationException::invalidValue('imports.templates.csv_delimiter', 'a map of locale => delimiter with a `default` entry');
        }

        return $delimiter;
    }

    public static function csvBom(): bool
    {
        return Config::boolean('imports.templates.csv_bom', true);
    }

    // Mappings ------------------------------------------------------------------------------

    public static function mappingsEnabled(): bool
    {
        return Config::boolean('imports.mappings.enabled', true);
    }

    public static function mappingsMaxPerImporter(): int
    {
        return Config::integer('imports.mappings.max_per_importer', 20, min: 1, max: 1000);
    }

    public static function mappingsMaxBytes(): int
    {
        return Config::integer('imports.mappings.max_bytes', 60000, min: 1024, max: 16_000_000);
    }

    public static function lockStore(): ?string
    {
        return self::optionalString('imports.mappings.lock_store', config('imports.mappings.lock_store'));
    }

    // Resources / routes --------------------------------------------------------------------

    /**
     * @return class-string<JsonResource>
     */
    public static function ownerResource(): string
    {
        return self::resource('imports.resources.owner', config('imports.resources.owner'), ImportOwnerResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function actorResource(): string
    {
        return self::resource('imports.resources.actor', config('imports.resources.actor'), ImportActorResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function recordResource(): string
    {
        return self::resource('imports.resources.record', config('imports.resources.record'), ImportRecordResource::class);
    }

    public static function routesEnabled(): bool
    {
        return Config::boolean('imports.routes.enabled', false);
    }

    /**
     * Uploads per minute per authenticated user (per IP without one) through the JSON API — one
     * budget across every owner the user imports for; 0 = no limit.
     */
    public static function uploadLimit(): int
    {
        return Config::integer('imports.routes.upload_limit', 10, min: 0, max: 10_000);
    }

    public static function routesPrefix(): string
    {
        return trim(Config::string('imports.routes.prefix', 'api/imports'), '/');
    }

    /**
     * @return list<string>
     */
    public static function routesMiddleware(): array
    {
        return Config::list('imports.routes.middleware', ['api', 'auth']);
    }

    // Helpers -------------------------------------------------------------------------------

    public static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function optionalString(string $key, mixed $value): ?string
    {
        if (self::blank($value)) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidImportsConfigurationException::invalidValue($key, 'a string');
        }

        return $value;
    }

    private static function duration(string $key, mixed $value, string $default): CarbonInterval
    {
        $value = self::blank($value) ? $default : $value;

        if (! is_string($value)) {
            throw InvalidImportsConfigurationException::invalidDuration($key, $value);
        }

        $interval = Durations::iso($value);

        if ($interval === null || Durations::seconds($interval) <= 0) {
            throw InvalidImportsConfigurationException::invalidDuration($key, $value);
        }

        return $interval;
    }

    /**
     * @param  class-string<JsonResource>  $default
     * @return class-string<JsonResource>
     */
    private static function resource(string $key, mixed $value, string $default): string
    {
        if (self::blank($value)) {
            return $default;
        }

        if (! is_string($value)
            || ! class_exists($value)
            || ! is_subclass_of($value, JsonResource::class)
            || ! (new ReflectionClass($value))->isInstantiable()) {
            throw InvalidImportsConfigurationException::notAnImplementation($key, JsonResource::class, $value);
        }

        return $value;
    }
}
