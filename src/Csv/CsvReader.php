<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Csv;

use Generator;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Reading\RowSource;

/**
 * A streaming RFC 4180 reader: `fgetcsv()` with `"` quotes and no escape character, quoted
 * delimiters and newlines kept, a UTF-8 BOM and an Excel `sep=` line skipped, CR-only files read
 * line by line. Row numbers are the records a spreadsheet would show (blank records count, but
 * are not yielded); a record can be re-read from its stored byte offset.
 *
 * @internal
 */
final class CsvReader implements RowSource
{
    /** @var resource|null */
    private $handle;

    private ?int $lastOffset = null;

    private int $dataStart = 0;

    private ?string $hint = null;

    private bool $crOnly = false;

    public function __construct(
        private readonly string $path,
        private ?string $delimiter,
        private readonly int $maxColumns,
        private readonly bool $unescapeFormulaPrefix = true,
        private readonly int $startOffset = 0,
        private readonly int $startRow = 1,
    ) {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        $this->handle = $handle;
        $this->inspect();
    }

    /**
     * The first bytes after any BOM and `sep=` line, for delimiter sniffing.
     */
    public function sample(int $bytes): string
    {
        $handle = $this->open();
        fseek($handle, $this->dataStart);
        $sample = (string) fread($handle, max(1, $bytes));

        return $this->crOnly ? str_replace("\r", "\n", $sample) : $sample;
    }

    /**
     * The delimiter an Excel `sep=` first line declares, if any.
     */
    public function hint(): ?string
    {
        return $this->hint;
    }

    public function useDelimiter(string $delimiter): void
    {
        $this->delimiter = $delimiter;
    }

    public function delimiter(): string
    {
        return $this->delimiter ?? $this->hint ?? ',';
    }

    /**
     * @return Generator<int, list<Cell|null>>
     */
    public function rows(): Generator
    {
        $handle = $this->open();
        fseek($handle, $this->startOffset > 0 ? $this->startOffset : $this->dataStart);

        if ($this->crOnly) {
            CrLineEndingFilter::attach($handle);
        }

        $delimiter = $this->delimiter();
        $row = $this->startRow - 1;

        while (true) {
            $offset = ftell($handle);
            $record = fgetcsv($handle, 0, $delimiter, '"', '');

            if ($record === false) {
                break;
            }

            $row++;
            $cells = $this->cells($record);

            if ($cells === []) {
                continue;
            }

            $this->lastOffset = $offset === false ? null : $offset;

            yield $row => $cells;
        }
    }

    public function lastOffset(): ?int
    {
        return $this->lastOffset;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param  array<int, string|null>  $record
     * @return list<Cell|null>
     */
    private function cells(array $record): array
    {
        $last = -1;
        $cells = [];

        foreach (array_values($record) as $index => $value) {
            if ($value === null || $value === '') {
                $cells[] = null;

                continue;
            }

            if ($this->unescapeFormulaPrefix && strlen($value) > 1 && $value[0] === "'" && strpbrk($value[1], "=+-@\t\r") !== false) {
                $value = substr($value, 1);
            }

            $cells[] = Cell::text($value);
            $last = $index;
        }

        if ($last + 1 > $this->maxColumns) {
            throw ImportFileRejectedException::because(RejectionReason::TooManyColumns, ['limit' => $this->maxColumns, 'actual' => $last + 1]);
        }

        return array_slice($cells, 0, $last + 1);
    }

    /**
     * BOM, `sep=` hint and line endings, from the first 64 KB.
     */
    private function inspect(): void
    {
        $handle = $this->open();
        $head = (string) fread($handle, 65536);
        $start = str_starts_with($head, "\xEF\xBB\xBF") ? 3 : 0;

        if (preg_match('/^sep=(.)(\r\n|\n|\r)/', substr($head, $start), $match) === 1) {
            $this->hint = $match[1];
            $start += strlen($match[0]);
        }

        $this->dataStart = $start;
        $this->crOnly = str_contains($head, "\r") && ! str_contains($head, "\n");
    }

    /**
     * @return resource
     */
    private function open()
    {
        if (! is_resource($this->handle)) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        return $this->handle;
    }
}
