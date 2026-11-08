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
 * bumps `preview_version` and then drops the preview (row results, counts, offsets, working copy),
 * so a queued validation of the old state stops without writing.
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

    /**
     * The version moves first, then the old row results go — both under the session row lock a
     * validation run takes before each write. A run of the old version that tries to write after
     * this point fails its version check, so nothing of it is left on the reset session (row
     * results deleted before the bump could be re-filled by a stale window in between).
     */
    public static function resetPreview(ImportSession $session): void
    {
        $workingCopy = $session->working_copy_path;

        $session->getConnection()->transaction(static function () use ($session): void {
            $current = ImportModels::session()::query()->whereKey($session->getKey())->lockForUpdate()->value('preview_version');

            $session->forceFill([
                'counts' => [],
                'chunk_size' => null,
                'chunk_offsets' => null,
                'working_copy_path' => null,
                'processed_rows' => 0,
                'progress' => 0,
                'previewed_at' => null,
                'failure' => null,
                'preview_version' => max($session->preview_version, is_numeric($current) ? (int) $current : 0) + 1,
            ])->save();

            ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete();
        });

        if ($workingCopy !== null) {
            Storage::disk($session->file_disk)->delete($workingCopy);
        }
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
     * The headers as shown: an unnamed column reads "Column C" in `$locale` (default: the session's).
     *
     * @return list<string>
     */
    public static function displayHeaders(ImportSession $session, ?string $locale = null): array
    {
        $display = [];

        foreach ($session->headers as $index => $header) {
            $display[] = $header === null || $header === ''
                ? Locales::line('imports::templates.column', ['column' => CellReference::letters($index)], $locale ?? $session->locale)
                : $header;
        }

        return $display;
    }
}
