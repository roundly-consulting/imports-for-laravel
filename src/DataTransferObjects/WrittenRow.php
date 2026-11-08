<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Enums\RecordAction;

/**
 * One row a commit chunk wrote: what happened and to which record, waiting for its ledger row.
 *
 * @internal
 */
final readonly class WrittenRow
{
    public function __construct(
        public int $number,
        public RecordAction $action,
        public Model $record,
        public ?string $contentHash,
    ) {}
}
