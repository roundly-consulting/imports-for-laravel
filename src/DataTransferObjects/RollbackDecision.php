<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\RollbackOutcome;

/**
 * What undo decided for one created record, and why when that is not obvious.
 *
 * @internal
 */
final readonly class RollbackDecision
{
    public function __construct(
        public RollbackOutcome $outcome,
        public ?string $reason = null,
    ) {}
}
