<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * A suggested mapping of a file's columns to an importer's fields.
 */
final readonly class MappingSuggestion
{
    /**
     * @param  list<SuggestedColumn>  $columns
     * @param  list<string>  $unmappedRequired  required fields no column matched
     * @param  list<int>  $ignoredColumns  columns that map to nothing
     */
    public function __construct(
        public array $columns,
        public array $unmappedRequired,
        public array $ignoredColumns,
        public ?string $savedMapping = null,
    ) {}

    /**
     * Complete = no required field is missing and nothing was matched fuzzily: safe to apply
     * without asking.
     */
    public function isComplete(): bool
    {
        if ($this->unmappedRequired !== []) {
            return false;
        }

        foreach ($this->columns as $column) {
            if ($column->field !== null && $column->isFuzzy()) {
                return false;
            }
        }

        return true;
    }

    public function toMapping(): ColumnMapping
    {
        $columns = [];

        foreach ($this->columns as $column) {
            if ($column->field !== null) {
                $columns[] = new MappedColumn($column->index, $column->field);
            }
        }

        return new ColumnMapping($columns);
    }
}
