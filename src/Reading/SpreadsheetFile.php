<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Reading;

use Generator;
use RoundlyConsulting\Imports\Csv\CsvReader;
use RoundlyConsulting\Imports\Csv\DelimiterSniffer;
use RoundlyConsulting\Imports\Csv\EncodingGuard;
use RoundlyConsulting\Imports\DataTransferObjects\SheetInfo;
use RoundlyConsulting\Imports\Enums\DelimiterSource;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Xlsx\CellReference;
use RoundlyConsulting\Imports\Xlsx\XlsxReader;

/**
 * A CSV or XLSX file read as a stream of rows — the same reader the import pipeline uses,
 * available on its own through `Imports::read()`. Nothing is held in memory but the current row.
 *
 * ```php
 * $file = Imports::read($path, sheet: 'Assets');
 * $file->headers();                       // ['Asset tag', 'Name', …]
 * foreach ($file->rows() as $row => $cells) { … }   // keyed by the row number a user sees
 * ```
 */
final class SpreadsheetFile
{
    private ?XlsxReader $xlsx = null;

    private ?RowSource $current = null;

    /** @var list<string|null>|null */
    private ?array $headers = null;

    private function __construct(
        private readonly string $path,
        public readonly SourceFormat $format,
        private readonly ReadOptions $options,
        private readonly ?string $delimiter = null,
        private readonly ?DelimiterSource $delimiterSource = null,
        private readonly ?string $encoding = null,
    ) {
        if ($format === SourceFormat::Xlsx) {
            $this->xlsx = new XlsxReader($path, $options);
        }
    }

    public static function open(string $path, ?ReadOptions $options = null): self
    {
        $options ??= ReadOptions::fromConfig();
        $format = FormatDetector::detect($path, $options->limits->fileSizeBytes(), $options->allowMacroEnabled);

        if ($format === SourceFormat::Xlsx) {
            return new self($path, $format, $options);
        }

        $bom = EncodingGuard::check($path);
        $probe = new CsvReader($path, null, $options->limits->columns, $options->unescapeFormulaPrefix);
        $hint = $probe->hint();

        [$delimiter, $source] = match (true) {
            $options->delimiter !== null => [$options->delimiter, DelimiterSource::Override],
            $hint !== null => [$hint, DelimiterSource::Hint],
            default => [DelimiterSniffer::sniff($probe->sample($options->sniffBytes), $options->delimiters === [] ? [','] : $options->delimiters), DelimiterSource::Sniffed],
        };

        $probe->close();

        return new self($path, $format, $options, $delimiter, $source, $bom ? 'utf-8-bom' : 'utf-8');
    }

    public function format(): SourceFormat
    {
        return $this->format;
    }

    /**
     * @return list<SheetInfo>
     */
    public function sheets(): array
    {
        return $this->xlsx?->sheets() ?? [];
    }

    /**
     * Whether date serials in this file count from 1904 (an XLSX setting; CSV is always 1900).
     */
    public function date1904(): bool
    {
        return $this->xlsx?->date1904() ?? false;
    }

    public function sheet(): ?string
    {
        return $this->xlsx?->sheetName();
    }

    public function delimiter(): ?string
    {
        return $this->delimiter;
    }

    public function delimiterSource(): ?DelimiterSource
    {
        return $this->delimiterSource;
    }

    public function encoding(): ?string
    {
        return $this->encoding;
    }

    public function headerRow(): int
    {
        return $this->options->headerRow;
    }

    /**
     * The header row's cells as text (`null` for a blank header cell).
     *
     * @return list<string|null>
     */
    public function headers(): array
    {
        if ($this->headers !== null) {
            return $this->headers;
        }

        foreach ($this->source()->rows() as $number => $cells) {
            if ($number > $this->options->headerRow) {
                break;
            }

            if ($number === $this->options->headerRow) {
                $this->headers = array_map(static fn (?Cell $cell): ?string => $cell === null ? null : trim($cell->display()), $cells);

                break;
            }
        }

        $this->closeCurrent();

        if ($this->headers === null || array_filter($this->headers, static fn (?string $h): bool => $h !== null && $h !== '') === []) {
            throw ImportFileRejectedException::because(RejectionReason::NoHeader, ['row' => $this->options->headerRow]);
        }

        return $this->headers;
    }

    /**
     * Headers as a UI shows them: a blank header cell is `Column C`.
     *
     * @return list<string>
     */
    public function displayHeaders(): array
    {
        return array_map(
            static fn (?string $header, int $index): string => $header === null || $header === '' ? __('imports::templates.column', ['column' => CellReference::letters($index)]) : $header,
            $this->headers(),
            array_keys($this->headers()),
        );
    }

    /**
     * The data rows after the header row, keyed by row number. More than `limits.rows` data rows
     * rejects the file (the read stops at the first one over).
     *
     * @return Generator<int, list<Cell|null>>
     */
    public function rows(): Generator
    {
        $this->headers();
        $count = 0;

        try {
            foreach ($this->source()->rows() as $number => $cells) {
                if ($number <= $this->options->headerRow) {
                    continue;
                }

                if (++$count > $this->options->limits->rows) {
                    throw ImportFileRejectedException::because(RejectionReason::TooManyRows, ['limit' => $this->options->limits->rows]);
                }

                yield $number => $cells;
            }
        } finally {
            $this->closeCurrent();
        }
    }

    /**
     * Count the data rows in one full pass (enforcing every limit on the way).
     */
    public function count(): int
    {
        $count = 0;

        foreach ($this->rows() as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * The byte offset of the row `rows()` last yielded (CSV only), where a reader can seek back.
     *
     * @internal
     */
    public function lastOffset(): ?int
    {
        return $this->current?->lastOffset();
    }

    public function close(): void
    {
        $this->closeCurrent();
        $this->xlsx?->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function source(): RowSource
    {
        $this->closeCurrent();

        return $this->current = $this->xlsx ?? new CsvReader($this->path, $this->delimiter, $this->options->limits->columns, $this->options->unescapeFormulaPrefix);
    }

    private function closeCurrent(): void
    {
        if ($this->current instanceof CsvReader) {
            $this->current->close();
        }

        $this->current = null;
    }
}
