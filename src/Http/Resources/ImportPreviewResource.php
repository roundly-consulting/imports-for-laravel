<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Imports\DataTransferObjects\ImportPreview;

/**
 * The preview to review: counts, the plan (creates / updates / skips), the update notice, the mode.
 *
 * @property ImportPreview $resource
 */
final class ImportPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $preview = $this->resource;

        return [
            'counts' => $preview->counts->toArray(),
            'plan' => ['creates' => $preview->counts->creates, 'updates' => $preview->counts->updates, 'skips' => $preview->counts->skips],
            'update_notice' => $preview->updateNotice,
            'mode' => $preview->mode->value,
            'unmapped_columns' => $preview->unmappedColumns,
        ];
    }
}
