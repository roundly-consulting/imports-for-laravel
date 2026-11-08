<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * The size limits one import is held to: file size (KB), data rows, columns and the length of one
 * cell. An importer may tighten or widen them, never past the hard ceilings.
 */
final readonly class Limits
{
    public function __construct(
        public int $fileSizeKb,
        public int $rows,
        public int $columns,
        public int $cellLength,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            ImportsConfig::maxFileSizeKb(),
            ImportsConfig::maxRows(),
            ImportsConfig::maxColumns(),
            ImportsConfig::cellLength(),
        );
    }

    public function withFileSize(int $kilobytes): self
    {
        return new self(max(1, $kilobytes), $this->rows, $this->columns, $this->cellLength);
    }

    public function withRows(int $rows): self
    {
        return new self($this->fileSizeKb, max(1, min($rows, ImportsConfig::MAX_ROWS_CEILING)), $this->columns, $this->cellLength);
    }

    public function withColumns(int $columns): self
    {
        return new self($this->fileSizeKb, $this->rows, max(1, min($columns, ImportsConfig::MAX_COLUMNS_CEILING)), $this->cellLength);
    }

    public function fileSizeBytes(): int
    {
        return $this->fileSizeKb * 1024;
    }
}
