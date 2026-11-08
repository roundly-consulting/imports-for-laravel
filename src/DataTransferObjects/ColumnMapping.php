<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * Which column feeds which field — by column index, so duplicate headers stay unambiguous.
 */
final readonly class ColumnMapping
{
    /**
     * @param  list<MappedColumn>  $columns
     */
    public function __construct(public array $columns) {}

    /**
     * @param  array<int|string, string>  $map  column index => field
     */
    public static function fromArray(array $map): self
    {
        $columns = [];

        foreach ($map as $index => $field) {
            $columns[] = new MappedColumn((int) $index, $field);
        }

        return new self($columns);
    }

    /**
     * @return array<int, string> column index => field
     */
    public function toArray(): array
    {
        $map = [];

        foreach ($this->columns as $column) {
            $map[$column->index] = $column->field;
        }

        ksort($map);

        return $map;
    }

    public function columnOf(string $field): ?int
    {
        foreach ($this->columns as $column) {
            if ($column->field === $field) {
                return $column->index;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return array_map(static fn (MappedColumn $column): string => $column->field, $this->columns);
    }
}
