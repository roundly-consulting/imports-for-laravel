<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Mapping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Events\MappingSaved;
use RoundlyConsulting\Imports\Exceptions\InvalidMappingException;
use RoundlyConsulting\Imports\Exceptions\TooManySavedMappingsException;
use RoundlyConsulting\Imports\Mapping\HeaderNormalizer;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\SavedMappingStore;

/**
 * Save a session's mapping under a name for its owner, by normalised header — so the next file
 * with the same headers (in any order) maps itself.
 */
final readonly class SaveMappingAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private SavedMappingStore $mappings,
    ) {}

    public function execute(ImportSession $session, string $name, ?Model $actor): SavedMapping
    {
        if (! ImportsConfig::mappingsEnabled()) {
            throw TooManySavedMappingsException::disabled();
        }

        $importer = $this->registry->rows($session->importer);
        $owner = Audits::owner($session);
        Access::check($importer, $actor, $owner, ImportOperation::Upload);

        if ($session->mapping === null || $session->mapping === []) {
            throw InvalidMappingException::withErrors(['mapping' => [Locales::line('imports::exceptions.mapping.empty', [], $session->locale)]]);
        }

        $headers = $session->headers;
        $normalized = HeaderNormalizer::all($headers, $session->locale);
        $columns = [];

        foreach ($session->mapping as $index => $field) {
            $header = $normalized[(int) $index] ?? '';

            if ($header !== '') {
                $columns[$header] = $field;
            }
        }

        $mapping = new SavedMapping(
            name: trim($name),
            importer: $importer->key(),
            columns: $columns,
            signature: HeaderNormalizer::signature($headers, $session->locale),
            delimiter: $session->delimiter,
            sheet: $session->sheet,
            headerRow: $session->header_row,
        );

        $this->mappings->save($owner, $mapping);
        $saved = $this->mappings->find($owner, $importer->key(), $mapping->name) ?? $mapping;

        Event::dispatch(new MappingSaved(Audits::for($session, null, $actor), $saved));

        return $saved;
    }
}
