<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\TeamFactory;

/**
 * @property int $id
 * @property string $name
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
