<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Imports\DataTransferObjects\RecordReference;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * Renders the swappable reference resources in place, through the configured class.
 *
 * @internal
 */
final class References
{
    /**
     * @return array<array-key, mixed>|null
     */
    public static function owner(?Model $owner, Request $request): ?array
    {
        $class = ImportsConfig::ownerResource();

        return $owner === null ? null : self::plain((new $class($owner))->resolve($request));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function actor(?Model $actor, Request $request): ?array
    {
        $class = ImportsConfig::actorResource();

        return $actor === null ? null : self::plain((new $class($actor))->resolve($request));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function record(?RecordReference $record, Request $request): ?array
    {
        $class = ImportsConfig::recordResource();

        return $record === null ? null : self::plain((new $class($record))->resolve($request));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function plain(mixed $resolved): array
    {
        return is_array($resolved) ? $resolved : [];
    }
}
