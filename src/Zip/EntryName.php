<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Zip;

/**
 * Archive entry names are metadata only: they are checked here and never used as a path on disk.
 *
 * @internal
 */
final class EntryName
{
    /**
     * Whether the name is safe to show: not absolute, no `..` segment (after `\` → `/`), no
     * NUL/control characters, at most 255 bytes.
     */
    public static function isSafe(string $name): bool
    {
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return false;
        }

        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $normalized), true);
    }

    public static function basename(string $name): string
    {
        $normalized = rtrim(str_replace('\\', '/', $name), '/');
        $position = strrpos($normalized, '/');

        return $position === false ? $normalized : substr($normalized, $position + 1);
    }

    /**
     * @param  list<string>  $patterns  `__MACOSX/*`, `*.DS_Store`, `Thumbs.db`, …
     */
    public static function ignored(string $name, array $patterns): bool
    {
        $normalized = str_replace('\\', '/', $name);
        $base = self::basename($normalized);

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $normalized) || fnmatch($pattern, $base)) {
                return true;
            }
        }

        return false;
    }
}
