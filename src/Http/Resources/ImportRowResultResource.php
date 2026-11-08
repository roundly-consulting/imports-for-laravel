<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Imports\DataTransferObjects\CellError;
use RoundlyConsulting\Imports\DataTransferObjects\RowResult;
use RoundlyConsulting\Imports\Definition\FieldChange;

/**
 * One preview row: status, planned outcome, errors per cell (row number + column letter), the
 * matched record as a reference, and an update's field changes.
 *
 * @property RowResult $resource
 */
final class ImportRowResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'row' => $row->rowNumber,
            'status' => $row->status->value,
            'outcome' => $row->outcome?->value,
            'label' => $row->label,
            'errors' => array_map(static fn (CellError $e): array => [
                'field' => $e->field,
                'column' => $e->columnLetter,
                'code' => $e->code,
                'message' => $e->message,
                'value' => $e->value,
            ], $row->errors),
            'match' => References::record($row->match, $request),
            'changes' => array_map(static fn (FieldChange $c): array => ['field' => $c->field, 'from' => $c->from, 'to' => $c->to], $row->changes),
        ];
    }
}
