<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Imports\Casts\UtcDateTime;
use RoundlyConsulting\Imports\Concerns\HasConfigurableKey;
use RoundlyConsulting\Imports\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Imports\Database\Factories\ImportSessionFactory;
use RoundlyConsulting\Imports\Enums\DelimiterSource;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\MappingSource;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * One uploaded file and everything known about it: source, sheet, delimiter, headers, mapping,
 * mode, counts, progress and status. Row values are never stored here — they live only in the
 * source file (and an XLSX working copy) on the import disk.
 *
 * @property int|string $id
 * @property string $importer
 * @property ImportKind $kind
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property SessionStatus $status
 * @property SourceFormat|null $format
 * @property string $file_disk
 * @property string $file_path
 * @property string $file_name
 * @property int $file_size
 * @property string|null $file_sha256
 * @property string|null $encoding
 * @property string|null $delimiter
 * @property DelimiterSource|null $delimiter_source
 * @property string|null $sheet
 * @property list<array{name: string, index: int, visible: bool}>|null $sheets
 * @property int $header_row
 * @property list<string|null> $headers
 * @property array<int|string, string>|null $mapping
 * @property MappingSource|null $mapping_source
 * @property DuplicateMode $mode
 * @property string $locale
 * @property string $timezone
 * @property int $data_rows
 * @property array<string, int|string|null> $counts
 * @property int $processed_rows
 * @property int $progress
 * @property int $preview_version
 * @property int|null $chunk_size
 * @property list<array{0: int, 1: int}>|null $chunk_offsets
 * @property string|null $working_copy_path
 * @property string|null $lock_key
 * @property array<string, mixed>|null $failure
 * @property CarbonImmutable|null $previewed_at
 * @property CarbonImmutable|null $last_progress_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $file_pruned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model $owner
 * @property-read Model|null $actor
 * @property-read ImportBatch|null $batch
 */
class ImportSession extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<ImportSessionFactory> */
    use HasFactory;

    use SoftDeletes;
    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return ImportsConfig::table('sessions');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasOne<ImportBatch, $this>
     */
    public function batch(): HasOne
    {
        return $this->hasOne(ImportModels::batch(), 'session_id');
    }

    /**
     * @return HasMany<ImportRowResult, $this>
     */
    public function rowResults(): HasMany
    {
        return $this->hasMany(ImportModels::rowResult(), 'session_id');
    }

    /**
     * The session's API handle (the trusted, owner-less path — inside HTTP code, go through
     * `Imports::for($owner)->session($id)`).
     */
    public function handle(): SessionHandle
    {
        return app(ImportsManager::class)->session($this);
    }

    public function count(string $key): int
    {
        $value = $this->counts[$key] ?? 0;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ImportKind::class,
            'status' => SessionStatus::class,
            'format' => SourceFormat::class,
            'delimiter_source' => DelimiterSource::class,
            'mapping_source' => MappingSource::class,
            'mode' => DuplicateMode::class,
            'sheets' => 'array',
            'headers' => 'array',
            'mapping' => 'array',
            'counts' => 'array',
            'chunk_offsets' => 'array',
            'failure' => 'array',
            'file_size' => 'integer',
            'header_row' => 'integer',
            'data_rows' => 'integer',
            'processed_rows' => 'integer',
            'progress' => 'integer',
            'preview_version' => 'integer',
            'chunk_size' => 'integer',
            'previewed_at' => UtcDateTime::class,
            'last_progress_at' => UtcDateTime::class,
            'expires_at' => UtcDateTime::class,
            'file_pruned_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
            'deleted_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): ImportSessionFactory
    {
        return ImportSessionFactory::new();
    }
}
