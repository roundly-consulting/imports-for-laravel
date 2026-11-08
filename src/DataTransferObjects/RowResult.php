<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Definition\FieldChange;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;

/**
 * One row of the preview: its status, what the commit will do with it, its errors, the record it
 * matched and — for updates — what would change.
 */
final readonly class RowResult
{
    /**
     * @param  list<CellError>  $errors
     * @param  list<FieldChange>  $changes
     */
    public function __construct(
        public int $rowNumber,
        public RowStatus $status,
        public ?RowOutcome $outcome,
        public array $errors = [],
        public ?RecordReference $match = null,
        public array $changes = [],
        public ?string $label = null,
    ) {}
}
