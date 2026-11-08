<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * A ledger row for a created record.
 *
 * @extends Factory<ImportedRecord>
 */
final class ImportedRecordFactory extends Factory
{
    /** @var class-string<ImportedRecord> */
    protected $model = ImportedRecord::class;

    public function modelName(): string
    {
        return ImportModels::record();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'batch_id' => ImportBatchFactory::new(),
            'session_id' => static fn (array $attributes): mixed => ImportModels::batch()::query()->whereKey($attributes['batch_id'])->value('session_id'),
            'row_number' => 2,
            'record_type' => 'asset',
            'record_id' => 1,
            'action' => RecordAction::Created,
            'owner_digest' => str_repeat('0', 64),
        ];
    }

    public function inBatch(ImportBatch $batch): self
    {
        return $this->state(fn (): array => ['batch_id' => $batch->getKey(), 'session_id' => $batch->session_id]);
    }

    public function record(Model $record): self
    {
        return $this->state(fn (): array => ['record_type' => $record->getMorphClass(), 'record_id' => $record->getKey()]);
    }
}
