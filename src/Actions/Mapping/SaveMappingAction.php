<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Mapping;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;
use RoundlyConsulting\Imports\DataTransferObjects\SaveMappingData;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Events\MappingSaved;
use RoundlyConsulting\Imports\Exceptions\InvalidSavedMappingException;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\SavedMappingStore;

/**
 * Save a mapping under a name for its owner, by normalised header — so the next file with the same
 * headers (in any order) maps itself. The one path for both a session's mapping and one given by
 * host code: switched on, a row importer, `authorize(Upload)`, a 1–60 character name, every field
 * known and mapped once, then stored and `MappingSaved`.
 */
final readonly class SaveMappingAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private SavedMappingStore $mappings,
    ) {}

    public function execute(SaveMappingData $data): SavedMapping
    {
        if (! ImportsConfig::mappingsEnabled()) {
            throw InvalidSavedMappingException::disabled();
        }

        $importer = $this->registry->rows($data->importer);
        Access::check($importer, $data->actor, $data->owner, ImportOperation::Upload);

        $name = trim($data->name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw InvalidSavedMappingException::invalidName();
        }

        if ($data->columns === []) {
            throw InvalidSavedMappingException::empty();
        }

        $seen = [];

        foreach ($data->columns as $field) {
            if ($importer->field($field) === null) {
                throw InvalidSavedMappingException::unknownField($field);
            }

            if (isset($seen[$field])) {
                throw InvalidSavedMappingException::fieldTwice($field);
            }

            $seen[$field] = true;
        }

        $mapping = new SavedMapping(
            name: $name,
            importer: $importer->key(),
            columns: $data->columns,
            signature: $data->signature,
            delimiter: $data->delimiter,
            sheet: $data->sheet,
            headerRow: $data->headerRow,
        );

        $this->mappings->save($data->owner, $mapping);
        $saved = $this->mappings->find($data->owner, $importer->key(), $name) ?? $mapping;

        Event::dispatch(new MappingSaved($data->owner, $data->actor, $importer->key(), $saved, $data->sessionId));

        return $saved;
    }
}
