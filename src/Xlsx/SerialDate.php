<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use Carbon\CarbonImmutable;

/**
 * Excel serial dates. In the 1900 system serial 1 is 1900-01-01 and serial 60 is Lotus' phantom
 * 1900-02-29 (no such day: refused); from 61 on, the bug shifts every date by one. In the 1904
 * system serial 0 is 1904-01-01. The fraction is the time of day, rounded to the second. The
 * result is wall-clock time in the given timezone — spreadsheets have no timezone.
 *
 * @internal
 */
final class SerialDate
{
    public const int MAX = 2_958_465;   // 9999-12-31

    public static function toDate(string $serial, bool $date1904, string $timezone): ?CarbonImmutable
    {
        if (! is_numeric($serial)) {
            return null;
        }

        $value = (float) $serial;

        if ($value < 0 || $value >= self::MAX + 1 || (! $date1904 && $value >= 60 && $value < 61)) {
            return null;
        }

        $days = (int) floor($value);
        $seconds = (int) round(($value - $days) * 86400);

        if ($seconds >= 86400) {
            $days++;
            $seconds -= 86400;
        }

        $epoch = match (true) {
            $date1904 => CarbonImmutable::create(1904, 1, 1, 0, 0, 0, $timezone),
            $days < 60 => CarbonImmutable::create(1899, 12, 31, 0, 0, 0, $timezone),
            default => CarbonImmutable::create(1899, 12, 30, 0, 0, 0, $timezone),
        };

        return $epoch?->addDays($days)->addSeconds($seconds);
    }
}
