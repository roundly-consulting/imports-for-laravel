<?php

declare(strict_types=1);

namespace Workbench\App\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Imports\Contracts\ResolvesImportOwner;
use Workbench\App\Models\User;

/**
 * The workbench's owner: the signed-in user's team.
 */
final class TeamOwnerResolver implements ResolvesImportOwner
{
    public function resolve(Request $request): ?Model
    {
        $user = $request->user();

        return $user instanceof User ? $user->team : null;
    }
}
