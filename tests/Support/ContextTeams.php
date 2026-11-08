<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * A stand-in for teams-for-laravel's tenancy, built the same way: the current team lives in
 * Laravel's hidden Context (so a queued job restores the DISPATCHER's team), reads and writes of
 * scoped models fail closed without one, and `runAs()` swaps the context and restores it.
 */
final class ContextTeams
{
    public const string KEY = 'imports-tests.team';

    public static function current(): ?int
    {
        $team = Context::getHidden(self::KEY);

        return is_int($team) ? $team : null;
    }

    public static function set(?Model $team): void
    {
        $team === null ? Context::forgetHidden(self::KEY) : Context::addHidden(self::KEY, (int) $team->getKey());
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function runAs(Model $team, Closure $callback): mixed
    {
        $previous = Context::getHidden(self::KEY);
        Context::addHidden(self::KEY, (int) $team->getKey());

        try {
            return $callback();
        } finally {
            $previous === null ? Context::forgetHidden(self::KEY) : Context::addHidden(self::KEY, $previous);
        }
    }

    public static function require(): int
    {
        return self::current() ?? throw new RuntimeException('No team context: the query would leak across teams.');
    }
}
