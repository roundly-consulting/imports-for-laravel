<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

/**
 * Which number formats make a numeric cell a date (or a time of day). Built-in ids 14–22, 27–36,
 * 45–47 and 50–58 are dates; a custom code is a date when `y m d h s` appear outside quoted text,
 * `\`-escapes and `[...]` sections (elapsed `[h] [m] [s]` count as time).
 *
 * @internal
 */
final class NumberFormat
{
    /** @var list<int> */
    private const array TIME_ONLY = [18, 19, 20, 21, 45, 46, 47];

    public static function isDate(int $id, ?string $code): bool
    {
        if ($code === null) {
            return ($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58);
        }

        return self::tokens($code) !== '';
    }

    public static function isTimeOnly(int $id, ?string $code): bool
    {
        if ($code === null) {
            return in_array($id, self::TIME_ONLY, true);
        }

        $tokens = self::tokens($code);

        return $tokens !== '' && preg_match('/[yd]/', $tokens) !== 1 && preg_match('/[hs]/', $tokens) === 1;
    }

    /**
     * The date/time letters of a format code, with text, escapes and bracket sections removed
     * (an elapsed `[h]`, `[m]` or `[s]` counts as `h`).
     */
    private static function tokens(string $code): string
    {
        // Only the first section (positive numbers) decides.
        $section = self::firstSection($code);
        $section = (string) preg_replace('/"[^"]*"/', '', $section);
        $section = (string) preg_replace('/\\\\./', '', $section);
        $elapsed = preg_match('/\[(h+|m+|s+)\]/i', $section) === 1;
        $section = (string) preg_replace('/\[[^\]]*\]/', '', $section);
        $section = (string) preg_replace('/[_*]./', '', $section);
        $letters = preg_match_all('/[ymdhs]/i', $section, $matches) > 0 ? strtolower(implode('', $matches[0])) : '';

        return ($elapsed ? 'h' : '').$letters;
    }

    private static function firstSection(string $code): string
    {
        $inQuote = false;

        for ($i = 0, $length = strlen($code); $i < $length; $i++) {
            if ($code[$i] === '"') {
                $inQuote = ! $inQuote;
            } elseif ($code[$i] === '\\') {
                $i++;
            } elseif ($code[$i] === ';' && ! $inQuote) {
                return substr($code, 0, $i);
            }
        }

        return $code;
    }
}
