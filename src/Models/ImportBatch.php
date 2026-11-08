<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Imports\Casts\UtcDateTime;
use RoundlyConsulting\Imports\Concerns\HasConfigurableKey;
use RoundlyConsulting\Imports\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Imports\Database\Factories\ImportBatchFactory;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Handles\BatchHandle;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * The commit run of a session (1:1), backed by a Laravel job batch. Append-only: no soft deletes,
 * pruned with history retention.
 *
 * @property int|string $id
 * @property int|string $session_id
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string|null $rollback_actor_type
 * @property int|string|null $rollback_actor_id
 * @property string|null $laravel_batch_id
 * @property string|null $rollback_batch_id
 * @property BatchStatus $status
 * @property int $chunk_size
 * @property int $total_chunks
 * @property int $chunks_completed
 * @property array<string, int> $counts
 * @property RollbackStatus|null $rollback_status
 * @property array<string, int>|null $rollback_counts
 * @property array<string, mixed>|null $failure
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $undo_until
 * @property CarbonImmutable|null $rolled_back_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ImportSession $session
 * @property-read Model|null $actor
 * @property-read Model|null $rollbackActor
 */
class ImportBatch extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return ImportsConfig::table('batches');
    }

    /**
     * @return BelongsTo<ImportSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ImportModels::session(), 'session_id')->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function rollbackActor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ImportedRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(ImportModels::record(), 'batch_id');
    }

    public function handle(): BatchHandle
    {
        return app(ImportsManager::class)->batch($this);
    }

    /**
     * One stored count (`created`, `failed`, …), 0 when absent. Not `count()`: that stays Eloquent's
     * row count (`ImportSession::count()`).
     */
    public function countOf(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'rollback_status' => RollbackStatus::class,
            'counts' => 'array',
            'rollback_counts' => 'array',
            'failure' => 'array',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'chunks_completed' => 'integer',
            'started_at' => UtcDateTime::class,
            'finished_at' => UtcDateTime::class,
            'undo_until' => UtcDateTime::class,
            'rolled_back_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): ImportBatchFactory
    {
        return ImportBatchFactory::new();
    }
}
