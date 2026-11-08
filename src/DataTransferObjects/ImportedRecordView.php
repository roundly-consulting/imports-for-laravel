<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;

/**
 * One ledger row as a UI lists it: the record by its morph pair (no query — a deleted record keeps
 * rendering), what the import did and what undo did.
 */
final readonly class ImportedRecordView
{
    public function __construct(
        public int $rowNumber,
        public RecordReference $record,
        public RecordAction $action,
        public ?RollbackOutcome $rollbackOutcome = null,
        public ?string $rollbackReason = null,
    ) {}
}
