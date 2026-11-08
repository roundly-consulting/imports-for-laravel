<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * An invalid row with one cell error.
 *
 * @extends Factory<ImportRowResult>
 */
final class ImportRowResultFactory extends Factory
{
    /** @var class-string<ImportRowResult> */
    protected $model = ImportRowResult::class;

    public function modelName(): string
    {
        return ImportModels::rowResult();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_id' => ImportSessionFactory::new(),
            'row_number' => 2,
            'status' => RowStatus::Invalid,
            'errors' => [['field' => 'name', 'column' => 0, 'code' => 'required', 'message' => 'The name field is required.', 'value' => null]],
        ];
    }
}
