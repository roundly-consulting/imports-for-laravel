<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\StillImported;

/**
 * For host models an importer writes: read-only access to the import ledger, so an app can show
 * "Imported" as a record's source. It never mutates anything.
 *
 * @phpstan-require-extends Model
 */
trait HasImportSource
{
    /**
     * @return MorphMany<ImportedRecord, $this>
     */
    public function importRecords(): MorphMany
    {
        return $this->morphMany(ImportModels::record(), 'record');
    }

    /**
     * The latest batch that created, linked or updated this record and whose undo did not remove
     * it (an undo that kept the record — changed since the import — leaves it imported).
     */
    public function importBatch(): ?ImportBatch
    {
        $ledger = StillImported::constrain($this->importRecords()->getQuery())->latest('id')->first();

        return $ledger?->batch;
    }

    public function wasImported(): bool
    {
        return StillImported::constrain($this->importRecords()->getQuery())->exists();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeImportedIn(Builder $query, ImportBatch $batch): Builder
    {
        return $query->whereHas('importRecords', static fn (Builder $ledger): Builder => $ledger->where('batch_id', $batch->getKey()));
    }
}
