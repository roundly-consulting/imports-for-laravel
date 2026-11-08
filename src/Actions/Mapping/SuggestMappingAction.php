<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Mapping;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\DataTransferObjects\MappingSuggestion;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Mapping\HeaderNormalizer;
use RoundlyConsulting\Imports\Mapping\Suggester;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\SavedMappingStore;
use RoundlyConsulting\Imports\Support\SessionState;

/**
 * Suggest a mapping for a session's header row: a saved mapping of this owner for the same
 * headers first, then names, labels (both locales), aliases and fuzzy matches.
 */
final readonly class SuggestMappingAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private SavedMappingStore $mappings,
    ) {}

    public function execute(ImportSession $session, ?Model $actor): MappingSuggestion
    {
        $importer = $this->registry->rows($session->importer);
        $owner = Audits::owner($session);
        Access::check($importer, $actor, $owner, ImportOperation::View);

        $headers = $session->headers;
        $saved = ImportsConfig::mappingsEnabled() && ! HeaderNormalizer::collide($headers, $session->locale)
            ? $this->mappings->bySignature($owner, $importer->key(), HeaderNormalizer::signature($headers, $session->locale))
            : null;

        return Suggester::suggest($importer, $headers, SessionState::displayHeaders($session), $session->locale, $saved);
    }
}
