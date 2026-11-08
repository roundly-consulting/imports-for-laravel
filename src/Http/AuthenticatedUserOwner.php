<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Imports\Contracts\ResolvesImportOwner;

/**
 * The default owner of an HTTP request's imports: the authenticated user (single-tenant apps).
 */
final class AuthenticatedUserOwner implements ResolvesImportOwner
{
    public function resolve(Request $request): ?Model
    {
        $user = $request->user();

        return $user instanceof Model ? $user : null;
    }
}
