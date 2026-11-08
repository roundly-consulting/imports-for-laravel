<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Imports\Casts\UtcDateTime;
use RoundlyConsulting\Imports\Concerns\HasConfigurableKey;
use RoundlyConsulting\Imports\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Imports\Database\Factories\ImportedRecordFactory;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * The ledger: one row per record a commit created, linked or updated, keyed `(batch_id,
 * row_number)` — the polymorphic "imported from" pivot and the commit's idempotency key at once.
 * Append-only: no soft deletes (a trashed ledger row would break idempotency).
 *
 * @property int|string $id
 * @property int|string $batch_id
 * @property int|string $session_id
 * @property int $row_number
 * @property string $record_type
 * @property int|string $record_id
 * @property RecordAction $action
 * @property string|null $checksum
 * @property CarbonImmutable|null $record_updated_at
 * @property string $owner_digest
 * @property string|null $content_hash
 * @property CarbonImmutable|null $rolled_back_at
 * @property RollbackOutcome|null $rollback_outcome
 * @property string|null $rollback_reason
 * @property CarbonImmutable|null $created_at
 * @property-read ImportBatch $batch
 * @property-read ImportSession $session
 * @property-read Model|null $record
 */
class ImportedRecord extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<ImportedRecordFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return ImportsConfig::table('records');
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportModels::batch(), 'batch_id');
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
    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => RecordAction::class,
            'rollback_outcome' => RollbackOutcome::class,
            'row_number' => 'integer',
            'record_updated_at' => UtcDateTime::class,
            'rolled_back_at' => UtcDateTime::class,
            'created_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): ImportedRecordFactory
    {
        return ImportedRecordFactory::new();
    }
}
