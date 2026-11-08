<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Preview;

use RoundlyConsulting\Imports\DataTransferObjects\ImportCounts;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * Re-plan a previewed session for another duplicate mode from its stored matches — no re-read,
 * no re-validation: only matched rows change their outcome.
 *
 * @internal
 */
final readonly class ClassifyRowsAction
{
    public function execute(ImportSession $session, DuplicateMode $mode): ImportCounts
    {
        $outcome = match ($mode) {
            DuplicateMode::Skip => RowOutcome::Skip,
            DuplicateMode::Update => RowOutcome::Update,
            DuplicateMode::CreateNew => RowOutcome::Create,
        };

        ImportModels::rowResult()::query()
            ->where('session_id', $session->getKey())
            ->where('status', RowStatus::Matched->value)
            ->update(['outcome' => $outcome->value]);

        $counts = ImportCounts::fromArray($session->counts);
        $matched = $counts->matched;

        return new ImportCounts(
            total: $counts->total,
            valid: $counts->valid,
            invalid: $counts->invalid,
            duplicates: $counts->duplicates,
            duplicatesInFile: $counts->duplicatesInFile,
            matched: $matched,
            creates: $counts->valid + ($outcome === RowOutcome::Create ? $matched : 0),
            updates: $outcome === RowOutcome::Update ? $matched : 0,
            skips: $counts->duplicatesInFile + ($outcome === RowOutcome::Skip ? $matched : 0),
            templateRows: $counts->templateRows,
            blankRows: $counts->blankRows,
        );
    }
}
