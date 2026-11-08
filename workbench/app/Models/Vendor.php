<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\VendorFactory;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string|null $vat_id
 */
final class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): VendorFactory
    {
        return VendorFactory::new();
    }
}
