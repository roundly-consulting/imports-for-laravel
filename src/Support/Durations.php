<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Throwable;

/**
 * Durations: strict ISO-8601, as config writes them.
 *
 * @internal
 */
final class Durations
{
    private const string ISO = '/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+S)?)?$/';

    /**
     * A strict ISO-8601 duration, or null when `$value` is not one.
     */
    public static function iso(string $value): ?CarbonInterval
    {
        if (preg_match(self::ISO, $value) !== 1) {
            return null;
        }

        try {
            return new CarbonInterval($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The interval's length in seconds, measured from a fixed UTC instant so months and years
     * have a definite length.
     */
    public static function seconds(CarbonInterval $interval): int
    {
        $from = CarbonImmutable::createFromTimestampUTC(946_684_800);

        return (int) $from->add($interval)->getTimestamp() - (int) $from->getTimestamp();
    }
}
