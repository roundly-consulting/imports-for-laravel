<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Reading;

use RoundlyConsulting\Imports\Definition\Limits;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * How one file is read: the sheet, the delimiter override, the header row, the timezone dates are
 * read in, the limits, and the reader settings from config (read once, so a read never re-reads
 * config per row).
 */
final readonly class ReadOptions
{
    /**
     * @param  list<string>  $delimiters
     */
    public function __construct(
        public Limits $limits,
        public string|int|null $sheet = null,
        public ?string $delimiter = null,
        public int $headerRow = 1,
        public string $timezone = 'UTC',
        public array $delimiters = [',', ';'],
        public int $sniffBytes = 65536,
        public bool $unescapeFormulaPrefix = true,
        public int $maxEntries = 1000,
        public int $maxPartSize = 268_435_456,
        public int $maxInflated = 536_870_912,
        public int $maxBlankRows = 10000,
        public int $sharedStringsMemory = 2_097_152,
        public bool $allowMacroEnabled = false,
    ) {}

    public static function fromConfig(
        ?Limits $limits = null,
        string|int|null $sheet = null,
        ?string $delimiter = null,
        int $headerRow = 1,
        ?string $timezone = null,
    ): self {
        return new self(
            limits: $limits ?? Limits::fromConfig(),
            sheet: $sheet,
            delimiter: $delimiter,
            headerRow: max(1, $headerRow),
            timezone: $timezone ?? ImportsConfig::timezone(),
            delimiters: ImportsConfig::csvDelimiters(),
            sniffBytes: ImportsConfig::sniffBytes(),
            unescapeFormulaPrefix: ImportsConfig::unescapeFormulaPrefix(),
            maxEntries: ImportsConfig::xlsxMaxEntries(),
            maxPartSize: ImportsConfig::xlsxMaxPartSize(),
            maxInflated: ImportsConfig::xlsxMaxInflated(),
            maxBlankRows: ImportsConfig::xlsxMaxBlankRows(),
            sharedStringsMemory: ImportsConfig::sharedStringsMemory(),
            allowMacroEnabled: ImportsConfig::allowMacroEnabled(),
        );
    }

    public function with(string|int|null $sheet = null, ?string $delimiter = null, ?int $headerRow = null, bool $keepSheet = true, bool $keepDelimiter = true): self
    {
        return new self(
            $this->limits,
            $sheet ?? ($keepSheet ? $this->sheet : null),
            $delimiter ?? ($keepDelimiter ? $this->delimiter : null),
            max(1, $headerRow ?? $this->headerRow),
            $this->timezone,
            $this->delimiters,
            $this->sniffBytes,
            $this->unescapeFormulaPrefix,
            $this->maxEntries,
            $this->maxPartSize,
            $this->maxInflated,
            $this->maxBlankRows,
            $this->sharedStringsMemory,
            $this->allowMacroEnabled,
        );
    }
}
