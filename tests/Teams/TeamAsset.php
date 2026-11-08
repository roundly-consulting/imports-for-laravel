<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Teams;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Teams\Traits\ScopedToTeam;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 */
final class TeamAsset extends Model
{
    use ScopedToTeam;

    protected $table = 'team_assets';

    protected $guarded = [];
}
