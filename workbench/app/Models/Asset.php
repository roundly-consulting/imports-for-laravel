<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Imports\Concerns\HasImportSource;
use Workbench\App\Enums\AssetStatus;
use Workbench\Database\Factories\AssetFactory;

/**
 * @property int $id
 * @property int $team_id
 * @property string|null $asset_tag
 * @property string|null $serial_number
 * @property string $name
 * @property AssetStatus $status
 * @property string|null $purchased_on
 * @property string|null $price
 * @property bool $insured
 * @property int|null $vendor_id
 * @property int|null $assignee_id
 */
final class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    use HasImportSource;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => AssetStatus::class, 'insured' => 'boolean'];
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
