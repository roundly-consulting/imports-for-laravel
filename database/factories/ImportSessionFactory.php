<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * An uploaded CSV session with no file behind it — for lists, resources and state tests.
 *
 * @extends Factory<ImportSession>
 */
final class ImportSessionFactory extends Factory
{
    /** @var class-string<ImportSession> */
    protected $model = ImportSession::class;

    public function modelName(): string
    {
        return ImportModels::session();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'importer' => 'assets',
            'kind' => ImportKind::Rows,
            'owner_type' => 'user',
            'owner_id' => 1,
            'status' => SessionStatus::Uploaded,
            'format' => SourceFormat::Csv,
            'file_disk' => 'local',
            'file_path' => 'imports/factory/source.csv',
            'file_name' => 'assets.csv',
            'file_size' => 0,
            'header_row' => 1,
            'headers' => [],
            'mode' => DuplicateMode::Skip,
            'locale' => 'en',
            'timezone' => 'UTC',
            'data_rows' => 0,
            'counts' => [],
            'expires_at' => Clock::now()->addDays(2),
        ];
    }

    public function owner(Model $owner): self
    {
        return $this->state(fn (): array => ['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]);
    }

    public function status(SessionStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
