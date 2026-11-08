<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\DataTransferObjects\ColumnMapping;
use RoundlyConsulting\Imports\DataTransferObjects\MappingSuggestion;
use RoundlyConsulting\Imports\Enums\MappingSource;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Xlsx\CellReference;

/**
 * Session transitions shared by the source, mapping and mode actions: any change before commit
 * drops the preview (row results, counts, offsets, working copy) and bumps `preview_version`, so a
 * queued validation of the old state stops without writing.
 *
 * @internal
 */
final class SessionState
{
    public static function assertBeforeCommit(ImportSession $session): void
    {
        if (! $session->status->isBeforeCommit()) {
            throw ImportStateException::wrongStatus($session->status->value);
        }
    }

    public static function resetPreview(ImportSession $session): void
    {
        ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete();

        if ($session->working_copy_path !== null) {
            Storage::disk($session->file_disk)->delete($session->working_copy_path);
        }

        $session->forceFill([
            'counts' => [],
            'chunk_size' => null,
            'chunk_offsets' => null,
            'working_copy_path' => null,
            'processed_rows' => 0,
            'progress' => 0,
            'previewed_at' => null,
            'failure' => null,
            'preview_version' => $session->preview_version + 1,
        ]);
    }

    /**
     * Apply a suggestion when it is complete (status `mapped`), otherwise leave the session
     * waiting for a mapping (`needs_mapping`).
     */
    public static function applySuggestion(ImportSession $session, MappingSuggestion $suggestion): void
    {
        if ($suggestion->isComplete()) {
            $session->forceFill([
                'mapping' => $suggestion->toMapping()->toArray(),
                'mapping_source' => $suggestion->savedMapping !== null ? MappingSource::Saved : MappingSource::Suggested,
                'status' => SessionStatus::Mapped,
            ]);

            return;
        }

        $session->forceFill(['mapping' => null, 'mapping_source' => null, 'status' => SessionStatus::NeedsMapping]);
    }

    public static function mapping(ImportSession $session): ColumnMapping
    {
        $map = [];

        foreach ($session->mapping ?? [] as $index => $field) {
            $map[(int) $index] = $field;
        }

        return ColumnMapping::fromArray($map);
    }

    /**
     * @return list<string>
     */
    public static function displayHeaders(ImportSession $session): array
    {
        $display = [];

        foreach ($session->headers as $index => $header) {
            $display[] = $header === null || $header === ''
                ? Locales::line('imports::templates.column', ['column' => CellReference::letters($index)], $session->locale)
                : $header;
        }

        return $display;
    }
}
