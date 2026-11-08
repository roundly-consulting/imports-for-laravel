<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Imports\Casts\UtcDateTime;
use RoundlyConsulting\Imports\Concerns\HasConfigurableKey;
use RoundlyConsulting\Imports\Concerns\StoresUtcTimestamps;
use RoundlyConsulting\Imports\Database\Factories\ImportRowResultFactory;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * One row the preview (or commit) has something to say about: invalid, a duplicate, a match, a
 * failure — or, in file-batch mode, every entry. Plain valid rows are implied, never stored, and
 * no row values are kept (only a ≤ 100-character excerpt of a failing cell in `errors`).
 *
 * @property int|string $id
 * @property int|string $session_id
 * @property int $row_number
 * @property RowStatus $status
 * @property RowOutcome|null $outcome
 * @property list<array{field: string|null, column: int|null, code: string, message: string, value: string|null}>|null $errors
 * @property string|null $match_key
 * @property int|null $match_candidate
 * @property int|string|null $matched_record_id
 * @property string|null $label
 * @property int|null $file_size
 * @property string|null $content_hash
 * @property string|null $stored_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ImportSession $session
 */
class ImportRowResult extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<ImportRowResultFactory> */
    use HasFactory;

    use StoresUtcTimestamps;

    protected $guarded = [];

    public function getTable(): string
    {
        return ImportsConfig::table('row_results');
    }

    /**
     * @return BelongsTo<ImportSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ImportModels::session(), 'session_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RowStatus::class,
            'outcome' => RowOutcome::class,
            'errors' => 'array',
            'row_number' => 'integer',
            'match_candidate' => 'integer',
            'file_size' => 'integer',
            'created_at' => UtcDateTime::class,
            'updated_at' => UtcDateTime::class,
        ];
    }

    protected static function newFactory(): ImportRowResultFactory
    {
        return ImportRowResultFactory::new();
    }
}
