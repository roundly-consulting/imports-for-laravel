<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Rows;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Reading\Cell;

/**
 * One validated data row, handed to an importer's create/update callback: field values already
 * normalised, coerced and checked; references already resolved inside the owner.
 *
 * Field → value maps are keyed by the host's field names (data, not a fixed shape).
 */
final readonly class ImportRow
{
    /**
     * @param  array<string, mixed>  $values  every mapped or defaulted field
     * @param  array<string, string|null>  $raw  the cell text per mapped field
     * @param  array<string, Cell|null>  $cells
     * @param  array<string, Model|null>  $references
     * @param  list<string>  $mapped  the fields a column was mapped to
     * @param  array<string, string|null>  $storeAs  reference field => the column its key goes into
     */
    public function __construct(
        private int $rowNumber,
        private array $values,
        private array $raw = [],
        private array $cells = [],
        private array $references = [],
        private array $mapped = [],
        private array $storeAs = [],
    ) {}

    /**
     * The spreadsheet row number a user sees (header = 1).
     */
    public function number(): int
    {
        return $this->rowNumber;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return array_key_exists($field, $this->values) ? $this->values[$field] : $default;
    }

    /**
     * Whether a column was mapped to the field.
     */
    public function has(string $field): bool
    {
        return in_array($field, $this->mapped, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, mixed>
     */
    public function filled(): array
    {
        return array_filter($this->values, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function only(string ...$fields): array
    {
        return array_intersect_key($this->values, array_flip($fields));
    }

    /**
     * @return array<string, mixed>
     */
    public function except(string ...$fields): array
    {
        return array_diff_key($this->values, array_flip($fields));
    }

    /**
     * Every value, ready for `Model::create()`: a reference field becomes its `storeAs()` column
     * holding the referenced key (`vendor_id => 5`), or is left out when it declares none.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->values as $field => $value) {
            if (! array_key_exists($field, $this->references) && ! array_key_exists($field, $this->storeAs)) {
                $attributes[$field] = $value;

                continue;
            }

            if (($column = $this->storeAs[$field] ?? null) !== null) {
                $attributes[$column] = $this->references[$field]?->getKey();
            }
        }

        return $attributes;
    }

    /**
     * Like `attributes()`, restricted to the mapped fields whose cell was not blank — what an
     * update should write. A blank cell keeps the stored value; to clear values, write
     * `attributes()` instead.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];
        $attributes = $this->attributes();

        foreach ($this->mapped as $field) {
            $raw = $this->raw[$field] ?? null;

            if ($raw === null || trim($raw) === '') {
                continue;
            }

            $column = array_key_exists($field, $this->storeAs) || array_key_exists($field, $this->references) ? ($this->storeAs[$field] ?? null) : $field;

            if ($column !== null && array_key_exists($column, $attributes)) {
                $changes[$column] = $attributes[$column];
            }
        }

        return $changes;
    }

    /**
     * Whether the field's cell was mapped and not blank.
     */
    public function filledIn(string $field): bool
    {
        $raw = $this->raw[$field] ?? null;

        return $this->has($field) && $raw !== null && trim($raw) !== '';
    }

    public function reference(string $field): ?Model
    {
        return $this->references[$field] ?? null;
    }

    public function raw(string $field): ?string
    {
        return $this->raw[$field] ?? null;
    }

    public function cell(string $field): ?Cell
    {
        return $this->cells[$field] ?? null;
    }
}
