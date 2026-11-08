<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Http\Resources\ImportActorResource;
use RoundlyConsulting\Imports\Http\Resources\ImportOwnerResource;
use RoundlyConsulting\Imports\Http\Resources\ImportRecordResource;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Scoping\ExplicitOwnerScope;

/*
 * Every value is read strictly through RoundlyConsulting\Imports\Support\ImportsConfig: a typo
 * throws naming the key, and a blank value (a host's `KEY=`) takes the shipped default.
 */
return [

    /*
    | The importer classes (rows: extend Importer, files: extend FileImporter). More can be added
    | at runtime with Imports::register().
    */
    'importers' => [],

    /*
    | Key types (bigint, uuid, ulid). `key_type` governs the owner and actor morphs and must equal
    | `options.key_type` (saved mappings are owner-scoped options). `record_key_type` governs the
    | imported-record morph (blank = key_type). `primary_key_type` is the package tables' own ids.
    */
    'key_type' => env('IMPORTS_KEY_TYPE', 'bigint'),
    'record_key_type' => env('IMPORTS_RECORD_KEY_TYPE'),
    'primary_key_type' => env('IMPORTS_PRIMARY_KEY_TYPE', 'bigint'),

    /*
    | Swappable models: extend the packaged class and name the subclass here.
    */
    'models' => [
        'session' => ImportSession::class,
        'batch' => ImportBatch::class,
        'row_result' => ImportRowResult::class,
        'record' => ImportedRecord::class,
    ],

    'tables' => [
        'sessions' => 'import_sessions',
        'batches' => 'import_batches',
        'row_results' => 'import_row_results',
        'records' => 'imported_records',
    ],

    /*
    | Keeps every lookup and write inside the import's owner. The default applies each importer's
    | declared Ownership; the teams recipe in the docs runs inside Teams::runAs().
    */
    'owner_scope' => ExplicitOwnerScope::class,

    'storage' => [
        // Never a public disk: uploads hold personal data.
        'disk' => env('IMPORTS_DISK', 'local'),
        'path' => 'imports',
        // Source files and working copies are deleted by imports:prune after this (ISO-8601).
        'retention' => env('IMPORTS_RETENTION', 'P30D'),
        // Blank = sys_get_temp_dir()/roundly-imports.
        'tmp_path' => env('IMPORTS_TMP_PATH'),
        'tmp_ttl' => 'PT6H',
    ],

    'history' => [
        // Blank = keep sessions, batches and the ledger forever.
        'retention' => env('IMPORTS_HISTORY_RETENTION'),
    ],

    'limits' => [
        'file_size' => env('IMPORTS_MAX_FILE_SIZE', 10240),     // KB
        'rows' => env('IMPORTS_MAX_ROWS', 10000),               // hard ceiling 100,000
        'columns' => env('IMPORTS_MAX_COLUMNS', 100),           // hard ceiling 16,384
        'cell_length' => 32767,
    ],

    'csv' => [
        'delimiters' => [',', ';'],
        'sniff_bytes' => 65536,
        // Strip the `'` our own CSV writer puts before formula-like cells when it comes back.
        'unescape_formula_prefix' => true,
    ],

    'xlsx' => [
        'max_entries' => 1000,
        'max_part_size' => 268435456,       // bytes, per part, counted while inflating
        'max_inflated' => 536870912,        // bytes, every part one read opens together
        'max_blank_rows' => 10000,
        'shared_strings_memory' => 2097152, // bytes kept in memory before the spool goes to disk
        'allow_macro_enabled' => false,
    ],

    'headers' => [
        'locales' => ['en', 'sk'],
        'fuzzy' => true,
        'fuzzy_threshold' => 0.85,
    ],

    'values' => [
        'date_formats' => ['Y-m-d', 'd.m.Y', 'j.n.Y', 'j. n. Y'],
        'datetime_formats' => ['Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i', 'j.n.Y H:i'],
        // Blank = the application timezone.
        'timezone' => null,
    ],

    'preview' => [
        'sync_threshold' => env('IMPORTS_SYNC_THRESHOLD', 500),
        'window' => 500,
        'progress_every' => 250,
    ],

    'commit' => [
        'chunk_size' => 250,
        'max_chunks' => 100,
        'tries' => 3,
        'backoff' => [10, 60, 300],
        'timeout' => 600,
    ],

    'undo' => [
        'window' => env('IMPORTS_UNDO_WINDOW', 'P7D'),
    ],

    'sessions' => [
        'expire_after' => 'P2D',
        'stale_after' => 'PT30M',
        'abandon_after' => 'PT6H',
    ],

    'queue' => [
        // Off = previews validate in the request and batches run on the `sync` connection.
        'enabled' => env('IMPORTS_QUEUE_ENABLED', true),
        'connection' => env('IMPORTS_QUEUE_CONNECTION'),
        'queue' => env('IMPORTS_QUEUE_NAME'),
    ],

    'files' => [
        'max_files' => 500,
        'max_uncompressed' => 1073741824,   // bytes
        'max_ratio' => 100,
        'ratio_min_size' => 1048576,        // bytes
        'max_file_size' => 51200,           // KB
        'max_archive_size' => 1048576,      // KB
        'ignore' => ['__MACOSX/*', '*.DS_Store', 'Thumbs.db', 'desktop.ini'],
    ],

    'templates' => [
        'default_format' => 'xlsx',
        'example_row' => true,
        'hint_row' => ['csv' => true, 'xlsx' => false],
        'data_validation' => true,
        'csv_delimiter' => ['default' => ',', 'sk' => ';'],
        'csv_bom' => true,
    ],

    'mappings' => [
        'enabled' => true,
        'max_per_importer' => 20,
        'max_bytes' => 60000,
        // Blank = the default cache store; use a shared store (redis, database) in production.
        'lock_store' => env('IMPORTS_LOCK_STORE'),
    ],

    'resources' => [
        // How an import's owner, actor and imported record render. Point a key at your own
        // JsonResource to add a name or avatar: owner and actor receive the model, record receives
        // an ImportedRecordView (the stored morph pair, so a deleted record keeps rendering).
        'owner' => ImportOwnerResource::class,
        'actor' => ImportActorResource::class,
        'record' => ImportRecordResource::class,
    ],

    'routes' => [
        // The JSON API is opt-in: on/off.
        'enabled' => env('IMPORTS_ROUTES') ?: 'off',
        'prefix' => 'api/imports',
        'middleware' => ['api', 'auth'],
        // A Contracts\ResolvesImportOwner class; null = the authenticated user owns its imports.
        'owner_resolver' => null,
    ],

];
