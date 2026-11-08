<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use BackedEnum;
use DateTimeInterface;

/**
 * One canonical string per value, so a row's key and a record's attribute compare equal whatever
 * the driver or the cast returns.
 *
 * @internal
 */
final class KeyValues
{
    public static function of(mixed $value, bool $caseInsensitive = false): ?string
    {
        $string = match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => null,
        };

        if ($string === null || $string === '') {
            return null;
        }

        return $caseInsensitive ? mb_strtolower($string) : $string;
    }
}
