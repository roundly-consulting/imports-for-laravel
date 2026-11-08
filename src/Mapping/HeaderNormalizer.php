<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Mapping;

use Illuminate\Support\Str;
use RoundlyConsulting\Imports\Support\Digests;

/**
 * Header text → a comparable key: ASCII-folded, lower-cased, a trailing `*` / `(required)` /
 * `(povinné)` dropped, every other non-alphanumeric run turned into one `_`.
 * `Inventárne č. *` → `inventarne_c`.
 *
 * @internal
 */
final class HeaderNormalizer
{
    public static function normalize(?string $header, string $locale = 'en'): string
    {
        if ($header === null) {
            return '';
        }

        $value = strtolower(Str::ascii($header, $locale));
        $value = (string) preg_replace('/\s*(\*|\((required|povinne)\))\s*$/', '', trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }

    /**
     * @param  list<string|null>  $headers
     * @return list<string>
     */
    public static function all(array $headers, string $locale = 'en'): array
    {
        return array_map(static fn (?string $header): string => self::normalize($header, $locale), $headers);
    }

    /**
     * A digest of the normalised header row: identical headers → the same signature. Always
     * transliterated under `en`, whatever the session locale (`ä` is `ae` under `de`, `a` under
     * `en`), so a saved mapping is recognised by every user of the owner.
     *
     * @param  list<string|null>  $headers
     */
    public static function signature(array $headers): string
    {
        return Digests::hex(implode("\x1f", self::all($headers)));
    }

    /**
     * @param  list<string|null>  $headers
     */
    public static function collide(array $headers, string $locale = 'en'): bool
    {
        $normalized = array_filter(self::all($headers, $locale), static fn (string $h): bool => $h !== '');

        return count($normalized) !== count(array_unique($normalized));
    }
}
