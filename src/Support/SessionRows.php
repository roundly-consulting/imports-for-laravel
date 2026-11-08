<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Generator;
use RoundlyConsulting\Imports\Csv\CsvReader;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Reading\Cell;

/**
 * A session's data rows after the preview: one commit chunk straight from its stored offset (the
 * CSV source, or the XLSX working copy), or a given set of rows — never a re-parse of a workbook.
 *
 * @internal
 */
final class SessionRows
{
    /**
     * @return Generator<int, list<Cell|null>>
     */
    public static function chunk(ImportSession $session, int $index, string $temp, int $maxColumns): Generator
    {
        $offsets = $session->chunk_offsets ?? [];

        if (! isset($offsets[$index])) {
            return;
        }

        [$offset, $first] = $offsets[$index];
        $size = $session->chunk_size ?? ImportsConfig::chunkSize();
        $read = 0;

        foreach (self::from($session, (int) $offset, (int) $first, $temp, $maxColumns) as $number => $cells) {
            if ($read++ >= $size) {
                break;
            }

            yield $number => $cells;
        }
    }

    /**
     * @param  array<int, true>  $numbers
     * @return Generator<int, list<Cell|null>>
     */
    public static function pick(ImportSession $session, array $numbers, string $temp, int $maxColumns): Generator
    {
        if ($numbers === []) {
            return;
        }

        $last = max(array_keys($numbers));
        [$offset, $first] = ($session->chunk_offsets ?? [])[0] ?? [0, $session->header_row + 1];

        foreach (self::from($session, (int) $offset, (int) $first, $temp, $maxColumns) as $number => $cells) {
            if ($number > $last) {
                break;
            }

            if (isset($numbers[$number])) {
                yield $number => $cells;
            }
        }
    }

    /**
     * Every data row from a stored offset.
     *
     * @return Generator<int, list<Cell|null>>
     */
    public static function from(ImportSession $session, int $offset, int $firstRow, string $temp, int $maxColumns): Generator
    {
        if ($session->chunk_offsets === null) {
            throw ImportStateException::stalePreview();
        }

        if ($session->format === SourceFormat::Xlsx) {
            yield from WorkingCopy::read(SessionFiles::localWorkingCopy($session, $temp), $offset, $session->timezone);

            return;
        }

        $reader = new CsvReader(SessionFiles::localSource($session, $temp), $session->delimiter, $maxColumns, ImportsConfig::unescapeFormulaPrefix(), $offset, $firstRow);

        try {
            foreach ($reader->rows() as $number => $cells) {
                if ($number > $session->header_row) {
                    yield $number => $cells;
                }
            }
        } finally {
            $reader->close();
        }
    }
}
