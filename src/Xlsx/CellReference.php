<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

/**
 * A1-style references: column letters to a 0-based index and back (`A` → 0, `XFD` → 16383).
 *
 * @internal
 */
final class CellReference
{
    public static function column(string $reference): ?int
    {
        if (preg_match('/^\$?([A-Za-z]{1,3})\$?\d*$/', $reference, $match) !== 1) {
            return null;
        }

        $index = 0;

        foreach (str_split(strtoupper($match[1])) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    public static function row(string $reference): ?int
    {
        return preg_match('/(\d+)$/', $reference, $match) === 1 ? (int) $match[1] : null;
    }

    public static function letters(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
