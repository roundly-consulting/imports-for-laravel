<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use Carbon\CarbonImmutable;
use Generator;
use RoundlyConsulting\Imports\DataTransferObjects\SheetInfo;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Reading\ReadOptions;
use RoundlyConsulting\Imports\Reading\RowSource;
use Throwable;
use XMLReader;

/**
 * A streaming XLSX reader with no dependency beyond ext-zip and ext-xmlreader. Shared strings are
 * spooled (flat memory), only the parts it needs are opened, formulas are never evaluated (the
 * cached value is used), dates follow the workbook's date system as wall-clock time.
 *
 * @internal
 */
final class XlsxReader implements RowSource
{
    private readonly Package $package;

    private readonly Workbook $workbook;

    private ?Styles $styles = null;

    private ?SharedStrings $strings = null;

    private readonly SheetPart $sheet;

    public function __construct(string $path, private readonly ReadOptions $options)
    {
        $this->package = Package::open($path, $options->maxEntries, $options->maxPartSize, $options->maxInflated);

        try {
            $this->workbook = Workbook::read($this->package);
            $this->sheet = $this->workbook->select($options->sheet);
        } catch (Throwable $exception) {
            $this->package->close();

            throw $exception;
        }
    }

    /**
     * @return list<SheetInfo>
     */
    public function sheets(): array
    {
        return $this->workbook->sheetInfos();
    }

    /**
     * Whether the workbook counts dates from 1904 (old Mac Excel) instead of 1900.
     */
    public function date1904(): bool
    {
        return $this->workbook->date1904;
    }

    public function sheetName(): string
    {
        return $this->sheet->name;
    }

    /**
     * @return Generator<int, list<Cell|null>>
     */
    public function rows(): Generator
    {
        $this->styles ??= Styles::read($this->package, $this->workbook->stylesPart);
        $this->strings ??= SharedStrings::read($this->package, $this->workbook->sharedStringsPart, $this->options->sharedStringsMemory);

        $reader = $this->package->xml($this->sheet->part);

        if ($reader === null) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        $previous = 0;
        $lastFilled = 0;

        try {
            while ($this->package->read($reader)) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $number = (int) ($reader->getAttribute('r') ?? 0);
                $number = $number > $previous ? $number : $previous + 1;
                $previous = $number;
                $cells = $reader->isEmptyElement ? [] : $this->cells($reader);

                if ($cells === []) {
                    if ($number - $lastFilled > $this->options->maxBlankRows) {
                        break;      // a formatted tail: nothing more is coming
                    }

                    continue;
                }

                if ($number - $lastFilled - 1 > $this->options->maxBlankRows) {
                    break;
                }

                $lastFilled = $number;

                yield $number => $cells;
            }
        } finally {
            $reader->close();
        }
    }

    public function lastOffset(): ?int
    {
        return null;
    }

    public function close(): void
    {
        $this->strings?->close();
        $this->package->close();
    }

    /**
     * The cells of one `<row>`, positioned on it; consumes through `</row>`.
     *
     * @return list<Cell|null>
     */
    private function cells(XMLReader $reader): array
    {
        $cells = [];
        $column = -1;
        $last = -1;
        $depth = $reader->depth;

        while ($this->package->read($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }

            $reference = $reader->getAttribute('r');
            $column = ($reference !== null ? CellReference::column($reference) : null) ?? $column + 1;
            $cell = $this->cell($reader);

            if ($cell === null) {
                continue;
            }

            if ($column >= $this->options->limits->columns) {
                throw ImportFileRejectedException::because(RejectionReason::TooManyColumns, ['limit' => $this->options->limits->columns, 'actual' => $column + 1]);
            }

            $cells[$column] = $cell;
            $last = max($last, $column);
        }

        if ($last < 0) {
            return [];
        }

        $row = [];

        for ($index = 0; $index <= $last; $index++) {
            $row[] = $cells[$index] ?? null;
        }

        return $row;
    }

    /**
     * One `<c>`, positioned on it; consumes through `</c>`.
     */
    private function cell(XMLReader $reader): ?Cell
    {
        $type = $reader->getAttribute('t') ?? 'n';
        $style = (int) ($reader->getAttribute('s') ?? 0);
        $value = null;
        $inline = null;

        if (! $reader->isEmptyElement) {
            $depth = $reader->depth;

            while ($this->package->read($reader)) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth) {
                    break;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName === 'v') {
                    $value = $reader->readString();
                } elseif ($reader->localName === 'is') {
                    $inline = RichText::read($this->package, $reader);
                }
            }
        }

        return match ($type) {
            's' => $this->text($value === null || ! ctype_digit(trim($value)) ? null : $this->strings?->get((int) trim($value))),
            'inlineStr' => $this->text($inline ?? $value),
            'str' => $this->text($value === null ? null : RichText::decode($value)),
            'b' => $value === null || trim($value) === '' ? null : Cell::boolean(trim($value) === '1' || strtolower(trim($value)) === 'true'),
            'e' => $value === null || $value === '' ? null : Cell::error($value),
            'd' => $this->isoDate($value),
            default => $this->number($value, $style),
        };
    }

    private function text(?string $value): ?Cell
    {
        return $value === null || $value === '' ? null : Cell::text($value);
    }

    private function isoDate(?string $value): ?Cell
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            // Wall-clock time: the offset (if any) is dropped, never converted.
            $date = CarbonImmutable::parse(preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', trim($value)) ?? '', $this->options->timezone);
        } catch (Throwable) {
            return Cell::text($value);
        }

        return Cell::date($date);
    }

    private function number(?string $value, int $style): ?Cell
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (! is_numeric($value)) {
            return Cell::text($value);
        }

        $styles = $this->styles ?? Styles::empty();

        if ($style > 0 && $styles->isDate($style)) {
            $date = SerialDate::toDate($value, $this->workbook->date1904, $this->options->timezone);

            if ($date !== null) {
                return $styles->isTimeOnly($style) ? Cell::text($date->format('H:i:s')) : Cell::date($date);
            }
        }

        return Cell::number($value);
    }
}
