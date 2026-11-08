<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * A pending one-chunk batch of a fresh session.
 *
 * @extends Factory<ImportBatch>
 */
final class ImportBatchFactory extends Factory
{
    /** @var class-string<ImportBatch> */
    protected $model = ImportBatch::class;

    public function modelName(): string
    {
        return ImportModels::batch();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_id' => ImportSessionFactory::new(),
            'status' => BatchStatus::Pending,
            'chunk_size' => 250,
            'total_chunks' => 1,
            'chunks_completed' => 0,
            'counts' => [],
        ];
    }
}
