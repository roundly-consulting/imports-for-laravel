<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How an import's owner renders: its key and class basename, never a class name. Swap it with
 * `imports.resources.owner` to add a name or avatar; it receives the owner model.
 *
 * @property Model $resource
 */
final class ImportOwnerResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'type' => class_basename($this->resource)];
    }
}
