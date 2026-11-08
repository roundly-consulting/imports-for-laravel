<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Carbon\CarbonImmutable;
use Generator;
use RoundlyConsulting\Imports\Enums\CellType;
use RoundlyConsulting\Imports\Reading\Cell;
use RuntimeException;

/**
 * An XLSX sheet written once, at preview, as a normalised UTF-8 CSV of raw cell values and types
 * (`s` text, `n` number, `b` boolean, `d` date, `e` error), the row number first. A deflated sheet
 * cannot be seeked; this can — commit chunks jump straight to their stored offset. It lives beside
 * the source on the import disk and shares its retention.
 *
 * @internal
 */
final class WorkingCopy
{
    /** @var resource */
    private $handle;

    public function __construct(string $path)
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot write the working copy.');
        }

        $this->handle = $handle;
    }

    /**
     * @param  list<Cell|null>  $cells
     * @return int the offset the row starts at
     */
    public function write(int $number, array $cells): int
    {
        $offset = (int) ftell($this->handle);
        fputcsv($this->handle, [(string) $number, ...array_map(self::encode(...), $cells)], ',', '"', '');

        return $offset;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * @return Generator<int, list<Cell|null>>
     */
    public static function read(string $path, int $offset, string $timezone): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot read the working copy.');
        }

        try {
            fseek($handle, $offset);

            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($record === [null] || ! is_numeric($record[0] ?? null)) {
                    continue;
                }

                $number = (int) $record[0];
                $cells = [];

                foreach (array_slice($record, 1) as $value) {
                    $cells[] = self::decode((string) $value, $timezone);
                }

                yield $number => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function encode(?Cell $cell): string
    {
        if ($cell === null) {
            return '';
        }

        return match ($cell->type) {
            CellType::Text => 's'.$cell->display(),
            CellType::Number => 'n'.$cell->display(),
            CellType::Boolean => $cell->value === true ? 'b1' : 'b0',
            CellType::Date => 'd'.($cell->value instanceof CarbonImmutable ? $cell->value->format('Y-m-d H:i:s') : ''),
            CellType::Error => 'e'.$cell->display(),
        };
    }

    private static function decode(string $value, string $timezone): ?Cell
    {
        if ($value === '') {
            return null;
        }

        $rest = substr($value, 1);

        return match ($value[0]) {
            'n' => Cell::number($rest),
            'b' => Cell::boolean($rest === '1'),
            'd' => Cell::date(CarbonImmutable::createFromFormat('Y-m-d H:i:s', $rest, $timezone) ?: CarbonImmutable::parse($rest, $timezone)),
            'e' => Cell::error($rest),
            default => Cell::text($rest),
        };
    }
}
