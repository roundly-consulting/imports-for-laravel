<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Imports\DataTransferObjects\RecordReference;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Http\Requests\RecordsRequest;
use RoundlyConsulting\Imports\Http\Resources\ImportBatchResource;
use RoundlyConsulting\Imports\Http\Resources\References;
use RoundlyConsulting\Imports\Models\ImportedRecord;

/**
 * @internal
 */
final class BatchesController extends Controller
{
    public function show(Request $request, string $batch): JsonResponse
    {
        return (new ImportBatchResource($this->scoped($request)->batch($batch)))->response();
    }

    public function cancel(Request $request, string $batch): JsonResponse
    {
        return (new ImportBatchResource($this->scoped($request)->batch($batch)->cancel()))->response()->setStatusCode(202);
    }

    public function resume(Request $request, string $batch): JsonResponse
    {
        return (new ImportBatchResource($this->scoped($request)->batch($batch)->resume()))->response()->setStatusCode(202);
    }

    public function rollback(Request $request, string $batch): JsonResponse
    {
        return (new ImportBatchResource($this->scoped($request)->batch($batch)->rollback()))->response()->setStatusCode(202);
    }

    public function records(RecordsRequest $request, string $batch): JsonResponse
    {
        $action = $request->validated('action');
        $perPage = $request->validated('per_page');
        $records = $this->scoped($request)->batch($batch)   // records() asks authorize(View)
            ->records(is_string($action) ? RecordAction::from($action) : null)
            ->paginate(is_numeric($perPage) ? (int) $perPage : 50);

        return new JsonResponse([
            'data' => array_map(static fn (ImportedRecord $ledger): array => [
                'row' => $ledger->row_number,
                'action' => $ledger->action->value,
                'record' => References::record(new RecordReference($ledger->record_type, $ledger->record_id), $request),
                'rolled_back' => $ledger->rolled_back_at !== null,
                'rollback_outcome' => $ledger->rollback_outcome?->value,
            ], $records->items()),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total()],
        ]);
    }
}
