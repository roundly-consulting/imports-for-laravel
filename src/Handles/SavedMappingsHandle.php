<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Actions\Mapping\DeleteSavedMappingAction;
use RoundlyConsulting\Imports\Actions\Mapping\SaveMappingAction;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;
use RoundlyConsulting\Imports\DataTransferObjects\SaveMappingData;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\SavedMappingStore;

/**
 * One owner's saved mappings for one importer. Only this owner's option is ever touched.
 */
final readonly class SavedMappingsHandle
{
    public function __construct(
        private ImportsManager $manager,
        private Model $owner,
        private ?Model $actor,
        private string $importer,
    ) {}

    /**
     * @return list<SavedMapping>
     */
    public function all(): array
    {
        $this->authorize(ImportOperation::View);

        return $this->store()->all($this->owner, $this->importer);
    }

    public function find(string $name): ?SavedMapping
    {
        $this->authorize(ImportOperation::View);

        return $this->store()->find($this->owner, $this->importer, $name);
    }

    /**
     * Save a mapping from header text to field names (`['Inventárne č.' => 'asset_tag']`); the
     * header order given is the signature an identical file is recognised by. The same action,
     * checks and `MappingSaved` event as saving a session's mapping.
     *
     * @param  array<string, string>  $columns
     */
    public function save(string $name, array $columns): SavedMapping
    {
        $data = SaveMappingData::fromColumns($this->owner, $this->importer, $name, $columns, $this->actor);
        $saved = $this->manager->perform('saveMapping', SaveMappingAction::class, static fn (SaveMappingAction $action): SavedMapping => $action->execute($data), ['owner' => $this->owner, 'importer' => $this->importer, 'name' => $data->name, 'data' => $data]);

        return $saved instanceof SavedMapping ? $saved : new SavedMapping($data->name, $this->importer, $data->columns, $data->signature);
    }

    public function delete(string $name): bool
    {
        return (bool) $this->manager->perform(
            'deleteMapping',
            DeleteSavedMappingAction::class,
            fn (DeleteSavedMappingAction $action): bool => $action->execute($this->owner, $this->importer, $name, $this->actor),
            ['owner' => $this->owner, 'importer' => $this->importer, 'name' => $name],
        );
    }

    private function authorize(ImportOperation $operation): void
    {
        Access::check($this->manager->container()->make(ImporterRegistry::class)->find($this->importer), $this->actor, $this->owner, $operation);
    }

    private function store(): SavedMappingStore
    {
        return $this->manager->container()->make(SavedMappingStore::class);
    }
}
