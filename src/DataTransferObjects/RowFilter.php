<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;

/**
 * Which preview rows to list: by status, by planned outcome, or those with an error on a field.
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
    ) {}

    public static function all(): self
    {
        return new self;
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
}
