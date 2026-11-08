<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved mapping was deleted.
 */
final readonly class MappingDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $owner,
        public ?Model $actor,
        public string $importer,
        public string $name,
    ) {}
}
