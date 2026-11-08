<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

/**
 * Number handling in string arithmetic — never through a float, so `0.30000000000000004` rounds to
 * `0.30` and a 16-digit id keeps every digit the file had.
 *
 * @internal
 */
final class Numbers
{
    /**
     * A numeric string in plain notation: `1.2345E+3` → `1234.5`, `5.0` → `5`, `-0` → `0`.
     */
    public static function plain(string $number): ?string
    {
        $number = trim($number);

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $number, $match) !== 1 || ($match[2] === '' && ($match[3] ?? '') === '')) {
            return null;
        }

        $sign = $match[1] === '-' ? '-' : '';
        $integer = $match[2];
        $fraction = $match[3] ?? '';
        $exponent = isset($match[4]) ? (int) $match[4] : 0;
        $digits = $integer.$fraction;
        $point = strlen($integer) + $exponent;

        if ($point <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $integer = $digits.str_repeat('0', $point - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $point);
            $fraction = substr($digits, $point);
        }

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $result = $integer.($fraction === '' ? '' : '.'.$fraction);

        return $result === '0' ? '0' : $sign.$result;
    }

    /**
     * Text a person typed, in their locale: `1 234,5` (sk) or `1,234.5` (en) → `1234.5`. Spaces,
     * NBSPs and apostrophes group thousands; when both `.` and `,` appear the last one is the
     * decimal separator.
     */
    public static function parse(string $text, string $locale): ?string
    {
        $value = (string) preg_replace('/[\s\x{00A0}\x{202F}\'’]/u', '', $text);

        if ($value === '' || preg_match('/^[+-]?[\d.,]+([eE][+-]?\d+)?$/', $value) !== 1) {
            return null;
        }

        $decimal = self::decimalSeparator($locale);
        $hasDot = str_contains($value, '.');
        $hasComma = str_contains($value, ',');

        if ($hasDot && $hasComma) {
            $decimal = strrpos($value, '.') > strrpos($value, ',') ? '.' : ',';
        } elseif ($hasDot || $hasComma) {
            $separator = $hasDot ? '.' : ',';
            $other = $hasDot ? ',' : '.';
            $grouping = preg_match('/^[+-]?\d{1,3}(['.preg_quote($separator, '/').']\d{3})+$/', $value) === 1;

            // Repeated, or grouping thousands in a locale where it is not the decimal mark: thousands.
            $decimal = match (true) {
                substr_count($value, $separator) > 1 => $grouping ? $other : '',
                $separator === $decimal => $separator,
                $grouping => $other,
                default => $separator,
            };

            if ($decimal === '') {
                return null;
            }
        }

        $thousands = $decimal === '.' ? ',' : '.';
        $integer = explode($decimal, $value, 2)[0];

        // Thousands separators must group by three (`1.234.567`), never `1.2.3`.
        if (str_contains($integer, $thousands) && preg_match('/^[+-]?\d{1,3}('.preg_quote($thousands, '/').'\d{3})+$/', $integer) !== 1) {
            return null;
        }

        $value = str_replace($thousands, '', $value);
        $value = str_replace($decimal, '.', $value);

        if (substr_count($value, '.') > 1) {
            return null;
        }

        return self::plain($value);
    }

    /**
     * Round half-up to `$scale` digits, in string arithmetic.
     */
    public static function round(string $number, int $scale): string
    {
        $plain = self::plain($number) ?? '0';
        $negative = str_starts_with($plain, '-');
        $plain = ltrim($plain, '-');
        [$integer, $fraction] = array_pad(explode('.', $plain, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $kept = $integer.substr($fraction, 0, $scale);
        $roundUp = (int) $fraction[$scale] >= 5;

        if ($roundUp) {
            $digits = str_split($kept);

            for ($i = count($digits) - 1; $i >= 0; $i--) {
                if ($digits[$i] === '9') {
                    $digits[$i] = '0';

                    continue;
                }

                $digits[$i] = (string) ((int) $digits[$i] + 1);

                break;
            }

            $kept = ($i < 0 ? '1' : '').implode('', $digits);
        }

        $integerLength = strlen($kept) - $scale;
        $result = ltrim(substr($kept, 0, $integerLength), '0');
        $result = ($result === '' ? '0' : $result).($scale > 0 ? '.'.substr($kept, $integerLength) : '');

        return $negative && trim($result, '0.') !== '' ? '-'.$result : $result;
    }

    public static function decimalSeparator(string $locale): string
    {
        return in_array(strtolower(substr($locale, 0, 2)), ['en', 'ja', 'zh', 'ko', 'he', 'th'], true) ? '.' : ',';
    }

    public static function isInteger(string $plain): bool
    {
        return preg_match('/^-?\d+$/', $plain) === 1;
    }

    public static function fitsInt(string $plain): bool
    {
        return self::isInteger($plain) && filter_var($plain, FILTER_VALIDATE_INT) !== false;
    }
}
