<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Options\Support\OptionModel;

/**
 * Saved mappings live in options-for-laravel's table, which a host publishes and migrates itself.
 *
 * @internal
 */
final class OptionsTable
{
    public static function name(): string
    {
        $model = OptionModel::class();

        return (new $model)->getTable();
    }

    public static function exists(): bool
    {
        $model = OptionModel::class();
        $instance = new $model;

        return Schema::connection($instance->getConnectionName())->hasTable($instance->getTable());
    }
}
