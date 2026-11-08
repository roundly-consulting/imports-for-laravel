<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\DocumentFactory;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $path
 * @property int $size
 */
final class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
