<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Imports\DataTransferObjects\RecordReference;

/**
 * How an imported (or matched) record renders: its key and class basename from the stored morph
 * pair — no query, so a deleted record keeps rendering, and never a class name. Swap it with
 * `imports.resources.record`; it receives a RecordReference.
 *
 * @property RecordReference $resource
 */
final class ImportRecordResource extends JsonResource
{
    /**
     * @return array{id: int|string, type: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => class_basename(Relation::getMorphedModel($this->resource->type) ?? $this->resource->type),
        ];
    }
}
