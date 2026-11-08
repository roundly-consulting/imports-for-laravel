<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\KeyTypes;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UuidItem extends Model
{
    use HasUuids;

    protected $table = 'uuid_items';

    protected $guarded = [];
}
