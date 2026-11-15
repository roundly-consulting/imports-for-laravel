<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;

/**
 * Which preview rows to list: every row, the flagged ones, by status, by planned outcome, or those
 * with an error on a field.
 */
final readonly class RowFilter
{
    /**
     * @param  list<RowStatus>  $statuses
     */
    private function __construct(
        public array $statuses = [],
        public ?RowOutcome $outcome = null,
        public ?string $field = null,
        public bool $everyRow = false,
    ) {}

    /**
     * Every data row — new, update, skip and error — each with a label, in file order. A row
     * import lists its plain creates from the file, so before a preview (and once the file is
     * pruned) this lists the stored rows only. Before 1.1.0 it listed the stored rows: that is
     * {@see self::flagged()}.
     */
    public static function all(): self
    {
        return new self(everyRow: true);
    }

    /**
     * Every row the preview flagged — invalid, failed, a duplicate in the file, a match — and none
     * that is plainly valid: what `all()` listed for a row import before 1.1.0.
     */
    public static function flagged(): self
    {
        return new self([RowStatus::Invalid, RowStatus::Failed, RowStatus::DuplicateInFile, RowStatus::Matched]);
    }

    public static function invalid(): self
    {
        return new self([RowStatus::Invalid, RowStatus::Failed]);
    }

    public static function duplicates(): self
    {
        return new self([RowStatus::DuplicateInFile, RowStatus::Matched]);
    }

    public static function status(RowStatus ...$statuses): self
    {
        return new self(array_values($statuses));
    }

    public static function updates(): self
    {
        return new self([RowStatus::Matched], RowOutcome::Update);
    }

    public static function creates(): self
    {
        return new self([], RowOutcome::Create);
    }

    public static function skips(): self
    {
        return new self([], RowOutcome::Skip);
    }

    public static function field(string $field): self
    {
        return new self([RowStatus::Invalid, RowStatus::Failed], null, $field);
    }

    public function listsCreates(): bool
    {
        return $this->outcome === RowOutcome::Create;
    }

    /**
     * Whether plainly valid rows — never stored for a row import — can be in the list.
     */
    public function listsValidRows(): bool
    {
        return $this->everyRow || $this->listsCreates() || in_array(RowStatus::Valid, $this->statuses, true);
    }
}
