<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Who owns the imports of an HTTP request (`imports.routes.owner_resolver`). The default is the
 * authenticated user; the teams recipe returns the current team.
 */
interface ResolvesImportOwner
{
    public function resolve(Request $request): ?Model;
}
