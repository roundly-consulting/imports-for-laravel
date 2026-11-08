<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * The preview's numbers. `total` = valid + invalid + duplicates; `duplicates` = rows matching an
 * existing record + repeats inside the file; creates / updates / skips follow the current mode.
 * Template and blank rows are reported but never counted in `total`.
 */
final readonly class ImportCounts
{
    public function __construct(
        public int $total = 0,
        public int $valid = 0,
        public int $invalid = 0,
        public int $duplicates = 0,
        public int $duplicatesInFile = 0,
        public int $matched = 0,
        public int $creates = 0,
        public int $updates = 0,
        public int $skips = 0,
        public int $templateRows = 0,
        public int $blankRows = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $counts
     */
    public static function fromArray(array $counts): self
    {
        $int = static fn (string $key): int => is_numeric($counts[$key] ?? null) ? (int) $counts[$key] : 0;

        return new self(
            $int('total'), $int('valid'), $int('invalid'), $int('duplicates'), $int('duplicates_in_file'),
            $int('matched'), $int('creates'), $int('updates'), $int('skips'), $int('template_rows'), $int('blank_rows'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'valid' => $this->valid,
            'invalid' => $this->invalid,
            'duplicates' => $this->duplicates,
            'duplicates_in_file' => $this->duplicatesInFile,
            'matched' => $this->matched,
            'creates' => $this->creates,
            'updates' => $this->updates,
            'skips' => $this->skips,
            'template_rows' => $this->templateRows,
            'blank_rows' => $this->blankRows,
        ];
    }
}
