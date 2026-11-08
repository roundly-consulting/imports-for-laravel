<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use RoundlyConsulting\Imports\DataTransferObjects\ImportedRecordView;
use RoundlyConsulting\Imports\DataTransferObjects\RecordReference;
use RoundlyConsulting\Imports\DataTransferObjects\RollbackReport;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;

/**
 * The undo report, from the ledger.
 *
 * @internal
 */
final class RollbackReports
{
    public static function build(ImportBatch $batch): RollbackReport
    {
        $counts = array_fill_keys(array_map(static fn (RollbackOutcome $o): string => $o->value, RollbackOutcome::cases()), 0);
        $linked = 0;
        $kept = [];

        foreach (ImportModels::record()::query()->where('batch_id', $batch->getKey())->whereIn('action', [RecordAction::Created->value, RecordAction::Linked->value])->orderBy('row_number')->cursor() as $ledger) {
            if ($ledger->action === RecordAction::Linked) {
                $linked++;
                $kept[] = self::view($ledger);

                continue;
            }

            if ($ledger->rollback_outcome === null) {
                continue;
            }

            $counts[$ledger->rollback_outcome->value]++;

            if (in_array($ledger->rollback_outcome, [RollbackOutcome::Kept, RollbackOutcome::KeptChanged, RollbackOutcome::Failed], true)) {
                $kept[] = self::view($ledger);
            }
        }

        return new RollbackReport(
            deleted: $counts['deleted'],
            handled: $counts['handled'],
            keptChanged: $counts['kept_changed'],
            keptByHook: $counts['kept'],
            linked: $linked,
            missing: $counts['missing'],
            failed: $counts['failed'],
            kept: $kept,
        );
    }

    public static function view(ImportedRecord $ledger): ImportedRecordView
    {
        return new ImportedRecordView(
            $ledger->row_number,
            new RecordReference($ledger->record_type, $ledger->record_id),
            $ledger->action,
            $ledger->rollback_outcome,
            $ledger->rollback_reason,
        );
    }
}
