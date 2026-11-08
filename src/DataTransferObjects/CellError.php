<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Xlsx\CellReference;

/**
 * One error on one cell (or the row, when `field` is null), named the way a user finds it: the
 * spreadsheet column letter and the row number.
 */
final readonly class CellError
{
    public function __construct(
        public ?string $field,
        public ?int $column,
        public ?string $columnLetter,
        public string $code,
        public string $message,
        public ?string $value,
    ) {}

    /**
     * A row-level error (no field, no column).
     */
    public static function row(string $code, string $message, ?string $value = null): self
    {
        return new self(null, null, null, $code, $message, $value);
    }

    public static function at(?string $field, ?int $column, string $code, string $message, ?string $value): self
    {
        return new self($field, $column, $column === null ? null : CellReference::letters($column), $code, $message, $value === null ? null : mb_substr($value, 0, 100));
    }

    /**
     * Read back from the row results' `errors` json column.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $field = $stored['field'] ?? null;
        $column = $stored['column'] ?? null;
        $code = $stored['code'] ?? null;
        $message = $stored['message'] ?? null;
        $value = $stored['value'] ?? null;

        return self::at(
            is_string($field) ? $field : null,
            is_int($column) ? $column : null,
            is_string($code) ? $code : '',
            is_string($message) ? $message : '',
            is_string($value) ? $value : null,
        );
    }

    /**
     * The `errors` json column payload (the letter is derived, so not stored).
     *
     * @return array{field: string|null, column: int|null, code: string, message: string, value: string|null}
     */
    public function stored(): array
    {
        return ['field' => $this->field, 'column' => $this->column, 'code' => $this->code, 'message' => $this->message, 'value' => $this->value];
    }

    /**
     * @param  list<self>  $errors
     * @return list<array{field: string|null, column: int|null, code: string, message: string, value: string|null}>
     */
    public static function storedList(array $errors): array
    {
        return array_map(static fn (self $error): array => $error->stored(), $errors);
    }
}
