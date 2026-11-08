<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Exception;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\LostConnectionException;
use Throwable;

/**
 * The exception taxonomy of a commit: a database concurrency or lost-connection error — or any
 * non-Exception Throwable — is infrastructure: rethrown, so the chunk rolls back and the job
 * retries. Anything else fails only the row it came from.
 *
 * @internal
 */
final class Failures
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

    public static function isInfrastructure(Throwable $exception): bool
    {
        if (! $exception instanceof Exception || $exception instanceof LostConnectionException) {
            return true;
        }

        $detector = new self;

        return $detector->causedByLostConnection($exception) || $detector->causedByConcurrencyError($exception);
    }
}
