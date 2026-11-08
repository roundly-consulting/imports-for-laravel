<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Mapping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Events\MappingDeleted;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\SavedMappingStore;

/**
 * Delete one saved mapping of an owner.
 */
final readonly class DeleteSavedMappingAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private SavedMappingStore $mappings,
    ) {}

    public function execute(Model $owner, string $importer, string $name, ?Model $actor): bool
    {
        Access::check($this->registry->rows($importer), $actor, $owner, ImportOperation::Upload);

        $deleted = $this->mappings->delete($owner, $importer, $name);

        if ($deleted) {
            Event::dispatch(new MappingDeleted($owner, $actor, $importer, $name));
        }

        return $deleted;
    }
}
