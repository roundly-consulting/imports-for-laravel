<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How the user who ran an operation renders: its key and class basename, never a class name. Swap it with
 * `imports.resources.actor` to add a name or avatar; it receives the actor model.
 *
 * @property Model $resource
 */
final class ImportActorResource extends JsonResource
{
    /**
     * @return array{id: mixed, type: string}
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'type' => class_basename($this->resource)];
    }
}
