<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Reading;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Imports\Enums\CellType;

/**
 * One non-empty spreadsheet cell as the reader found it: the type the file declared and the raw
 * value — text, a numeric string (never a float, so no precision is lost), a boolean, a wall-clock
 * date or an error code. Empty cells are `null`, never a Cell.
 */
final readonly class Cell
{
    public function __construct(
        public CellType $type,
        public string|bool|CarbonImmutable $value,
    ) {}

    public static function text(string $value): self
    {
        return new self(CellType::Text, $value);
    }

    public static function number(string $value): self
    {
        return new self(CellType::Number, $value);
    }

    public static function boolean(bool $value): self
    {
        return new self(CellType::Boolean, $value);
    }

    public static function date(CarbonImmutable $value): self
    {
        return new self(CellType::Date, $value);
    }

    public static function error(string $code): self
    {
        return new self(CellType::Error, $code);
    }

    /**
     * The value as a user would read it in the sheet.
     */
    public function display(): string
    {
        return match (true) {
            $this->value instanceof CarbonImmutable => $this->value->format($this->value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            is_bool($this->value) => $this->value ? 'TRUE' : 'FALSE',
            default => $this->value,
        };
    }
}
