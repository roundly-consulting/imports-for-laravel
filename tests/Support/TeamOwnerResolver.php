<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Imports\Contracts\ResolvesImportOwner;
use Workbench\App\Models\User;

/**
 * The teams recipe's resolver, simplified: the request's imports belong to the user's team.
 */
final class TeamOwnerResolver implements ResolvesImportOwner
{
    public function resolve(Request $request): ?Model
    {
        $user = $request->user();

        return $user instanceof User ? $user->team : null;
    }
}
