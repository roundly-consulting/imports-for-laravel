<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Imports\DataTransferObjects\ImportedRecordView;
use RoundlyConsulting\Imports\Handles\BatchHandle;

/**
 * A commit run: status, progress, summary, the undo window and — once undone — the report.
 *
 * @property BatchHandle $resource
 */
final class ImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $handle = $this->resource;
        $batch = $handle->model();
        $progress = $handle->progress();
        $summary = $handle->summary();
        $report = $handle->rollbackReport();

        return [
            'id' => $batch->getKey(),
            'session_id' => $batch->session_id,
            'status' => $batch->status->value,
            'progress' => ['processed' => $progress->processed, 'total' => $progress->total, 'percent' => $progress->percent, 'phase' => $progress->phase],
            'summary' => [
                'created' => $summary->created,
                'linked' => $summary->linked,
                'updated' => $summary->updated,
                'skipped' => $summary->skipped,
                'failed' => $summary->failed,
                'reclassified' => $summary->reclassified,
                'duplicates' => $summary->duplicates,
                'invalid' => $summary->invalid,
                'duration' => $summary->durationSeconds,
            ],
            'undo_until' => $batch->undo_until?->toIso8601String(),
            'can_rollback' => $handle->canRollback(),
            'rollback_status' => $batch->rollback_status?->value,
            'rollback' => $report === null ? null : [
                'deleted' => $report->deleted,
                'handled' => $report->handled,
                'kept_changed' => $report->keptChanged,
                'kept' => $report->keptByHook,
                'linked' => $report->linked,
                'missing' => $report->missing,
                'failed' => $report->failed,
                'kept_records' => array_map(static fn (ImportedRecordView $v): array => [
                    'row' => $v->rowNumber,
                    'record' => References::record($v->record, $request),
                    'action' => $v->action->value,
                    'outcome' => $v->rollbackOutcome?->value,
                ], $report->kept),
            ],
            'actor' => References::actor($batch->actor_type === null ? null : $batch->actor()->getResults(), $request),
        ];
    }
}
