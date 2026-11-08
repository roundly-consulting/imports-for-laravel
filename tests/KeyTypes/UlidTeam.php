<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\KeyTypes;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class UlidTeam extends Model
{
    use HasUlids;

    protected $table = 'ulid_teams';

    protected $guarded = [];
}
