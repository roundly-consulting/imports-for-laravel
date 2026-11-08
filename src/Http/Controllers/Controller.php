<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Imports\Contracts\ResolvesImportOwner;
use RoundlyConsulting\Imports\Exceptions\ImportUnauthorizedException;
use RoundlyConsulting\Imports\Handles\OwnerImports;
use RoundlyConsulting\Imports\ImportsManager;

/**
 * Every endpoint reaches imports only through the owner-scoped handle.
 *
 * @internal
 */
abstract class Controller
{
    public function __construct(
        protected readonly ImportsManager $imports,
        private readonly ResolvesImportOwner $owners,
    ) {}

    protected function owner(Request $request): Model
    {
        return $this->owners->resolve($request) ?? throw ImportUnauthorizedException::operation('view');
    }

    protected function scoped(Request $request): OwnerImports
    {
        return $this->imports->for($this->owner($request))->by(self::actor($request));
    }

    protected static function actor(Request $request): ?Model
    {
        $user = $request->user();

        return $user instanceof Model ? $user : null;
    }
}
