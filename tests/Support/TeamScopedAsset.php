<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * An asset living under the context team scope and write guard (teams' ScopedToTeam shape):
 * reads are scoped, a write for another team — or with no team — throws.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $asset_tag
 * @property string $name
 */
final class TeamScopedAsset extends Model
{
    protected $table = 'assets';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::addGlobalScope(new ContextTeamScope);

        $guard = static function (Model $asset): void {
            if ((int) $asset->getAttribute('team_id') !== ContextTeams::require()) {
                throw new RuntimeException('Write outside the current team.');
            }
        };

        self::creating($guard);
        self::updating($guard);
        self::deleting($guard);
    }
}
