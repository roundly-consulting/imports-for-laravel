<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use XMLReader;

/**
 * The part of `styles.xml` that matters for reading: which cell style (`s` index into `cellXfs`)
 * carries a date or time number format.
 *
 * @internal
 */
final class Styles
{
    /**
     * @param  array<int, int>  $styleFormats  cellXfs index => numFmtId
     * @param  array<int, string>  $customFormats  numFmtId => formatCode
     */
    private function __construct(private readonly array $styleFormats, private readonly array $customFormats) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    public static function read(Package $package, ?string $part): self
    {
        $reader = $part === null ? null : $package->xml($part);

        if ($reader === null) {
            return self::empty();
        }

        $styleFormats = [];
        $customFormats = [];
        $inCellXfs = false;
        $index = 0;

        while ($package->read($reader)) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'cellXfs') {
                $inCellXfs = false;

                continue;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'numFmt') {
                $id = $reader->getAttribute('numFmtId');
                $code = $reader->getAttribute('formatCode');

                if ($id !== null && $code !== null) {
                    $customFormats[(int) $id] = $code;
                }
            } elseif ($reader->localName === 'cellXfs') {
                $inCellXfs = ! $reader->isEmptyElement;
            } elseif ($inCellXfs && $reader->localName === 'xf') {
                $styleFormats[$index++] = (int) ($reader->getAttribute('numFmtId') ?? 0);
            }
        }

        $reader->close();
        $package->assertHealthy();

        return new self($styleFormats, $customFormats);
    }

    public function isDate(int $style): bool
    {
        $id = $this->styleFormats[$style] ?? 0;

        return NumberFormat::isDate($id, $this->customFormats[$id] ?? null);
    }

    public function isTimeOnly(int $style): bool
    {
        $id = $this->styleFormats[$style] ?? 0;

        return NumberFormat::isTimeOnly($id, $this->customFormats[$id] ?? null);
    }
}
