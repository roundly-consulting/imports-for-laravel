<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Imports\DataTransferObjects\ImportCounts;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Abilities;
use RoundlyConsulting\Imports\Support\SessionState;

/**
 * An import session. No file path, disk or class name ever leaves the server: the file is its
 * original name and size; owner and actor render through the swappable reference resources.
 * Lists should eager-load `owner` and `actor`.
 *
 * @property ImportSession $resource
 */
final class ImportSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource;
        $registry = app(ImporterRegistry::class);
        $label = $registry->has($session->importer) ? $registry->find($session->importer)->labelIn() : $session->importer;
        $owner = $session->relationLoaded('owner') ? $session->getRelation('owner') : $session->owner()->getResults();
        $actor = $session->actor_type === null ? null : ($session->relationLoaded('actor') ? $session->getRelation('actor') : $session->actor()->getResults());
        $batch = $session->relationLoaded('batch') ? $session->getRelation('batch') : ($session->status->isBeforeCommit() ? null : $session->batch()->first());
        $counts = ImportCounts::fromArray($session->counts);

        return [
            'id' => $session->getKey(),
            'importer' => ['key' => $session->importer, 'label' => $label],
            'kind' => $session->kind->value,
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'format' => $session->format?->value,
            'file' => ['name' => $session->file_name, 'size' => $session->file_size],
            'sheet' => $session->sheet,
            'sheets' => $session->sheets ?? [],
            'delimiter' => $session->delimiter,
            'header_row' => $session->header_row,
            'headers' => SessionState::displayHeaders($session),
            // A list of pairs: a resource would re-index an array keyed by column number.
            'mapping' => array_map(static fn (int|string $column, string $field): array => ['column' => (int) $column, 'field' => $field], array_keys($session->mapping ?? []), array_values($session->mapping ?? [])),
            'mode' => $session->mode->value,
            'data_rows' => $session->data_rows,
            'counts' => $counts->toArray(),
            'progress' => ['processed' => $session->processed_rows, 'total' => $session->data_rows, 'percent' => $session->progress],
            'errors' => $counts->invalid,
            'batch_id' => $batch instanceof Model ? $batch->getKey() : null,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'can' => [
                // The rules the actions enforce: expiry, a failed undo that may run again, the undo window.
                'commit' => Abilities::canCommit($session),
                'rollback' => $batch instanceof ImportBatch && Abilities::canRollback($batch),
            ],
            'owner' => References::owner($owner instanceof Model ? $owner : null, $request),
            'actor' => References::actor($actor instanceof Model ? $actor : null, $request),
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }
}
