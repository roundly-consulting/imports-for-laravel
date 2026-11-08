<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Actions\Mapping\DeleteSavedMappingAction;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Exceptions\TooManySavedMappingsException;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Mapping\HeaderNormalizer;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;
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
     * header order given is the signature an identical file is recognised by.
     *
     * @param  array<string, string>  $columns
     */
    public function save(string $name, array $columns): SavedMapping
    {
        if (! ImportsConfig::mappingsEnabled()) {
            throw TooManySavedMappingsException::disabled();
        }

        $this->authorize(ImportOperation::Upload);
        $locale = Locales::current();
        $headers = array_map('strval', array_keys($columns));
        $normalized = [];

        foreach ($columns as $header => $field) {
            $normalized[HeaderNormalizer::normalize((string) $header, $locale)] = $field;
        }

        $mapping = new SavedMapping(trim($name), $this->importer, $normalized, HeaderNormalizer::signature($headers, $locale));

        return $this->manager->perform('saveMapping', SavedMappingStore::class, function (SavedMappingStore $store) use ($mapping): SavedMapping {
            $store->save($this->owner, $mapping);

            return $store->find($this->owner, $this->importer, $mapping->name) ?? $mapping;
        }, ['owner' => $this->owner, 'importer' => $this->importer, 'name' => $mapping->name]) ?? $mapping;
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
