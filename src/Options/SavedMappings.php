<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Options;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Options\BaseOption;

/**
 * Every saved column mapping of one owner, through options-for-laravel (one row per owner). Closed
 * to options' generic read/write API: the package reaches it only inside `withoutAuthorization()`,
 * behind the importer's own authorize() — a host's options UI cannot read or edit it.
 *
 * @internal
 */
final class SavedMappings extends BaseOption
{
    public function key(): string
    {
        return 'imports.mappings';
    }

    public function castAs(): string
    {
        return 'array';
    }

    public function default(): mixed
    {
        return [];
    }

    public function authorizeRead(?Authenticatable $user, ?Model $owner): bool
    {
        return false;
    }

    public function authorizeWrite(?Authenticatable $user, ?Model $owner): bool
    {
        return false;
    }
}
