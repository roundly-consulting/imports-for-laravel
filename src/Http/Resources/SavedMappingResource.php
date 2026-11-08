<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;

/**
 * A saved mapping: its name and normalised header → field pairs.
 *
 * @property SavedMapping $resource
 */
final class SavedMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'importer' => $this->resource->importer,
            'columns' => $this->resource->columns,
            'updated_at' => $this->resource->updatedAt,
        ];
    }
}
