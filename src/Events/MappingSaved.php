<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;

/**
 * A mapping was saved under a name for its owner — from a session (`sessionId` set) or by host
 * code through `mappings($importer)->save()`.
 */
final readonly class MappingSaved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $owner,
        public ?Model $actor,
        public string $importer,
        public SavedMapping $mapping,
        public int|string|null $sessionId = null,
    ) {}
}
