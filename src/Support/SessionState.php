<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Closure;
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
 * so a queued validation of the old state stops without writing. Each runs inside `transition()`,
 * so it cannot overwrite a commit that took the session meanwhile.
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
     * Run a pre-commit transition's writes in one transaction, after reading the session row
     * again under its lock: it must still be before commit and hold no commit or undo lock (and,
     * with `$version`, still be on that preview version). A commit that started after the
     * action's own checks is refused here, never overwritten; a commit that comes after this
     * point finds the preview version moved.
     *
     * With `$resetPreview` the preview is dropped first: the version moves, then the old row
     * results go, under the same lock a validation run takes before each write. A run of the old
     * version that tries to write after this point fails its version check, so nothing of it is
     * left on the reset session (row results deleted before the bump could be re-filled by a
     * stale window in between). The working copy is deleted once the transaction commits.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public static function transition(ImportSession $session, Closure $write, ?int $version = null, bool $resetPreview = false): mixed
    {
        return $session->getConnection()->transaction(static function () use ($session, $write, $version, $resetPreview): mixed {
            $current = ImportModels::session()::query()->withTrashed()->whereKey($session->getKey())->lockForUpdate()->first();

            if ($current === null || $current->trashed() || ! $current->status->isBeforeCommit() || $current->lock_key !== null) {
                throw ImportStateException::wrongStatus(($current ?? $session)->status->value);
            }

            if ($version !== null && $current->preview_version !== $version) {
                throw ImportStateException::stalePreview();
            }

            if ($resetPreview) {
                self::resetPreview($session, $current->preview_version);
            }

            return $write();
        });
    }

    private static function resetPreview(ImportSession $session, int $current): void
    {
        $workingCopy = $session->working_copy_path;

        $session->forceFill([
            'counts' => [],
            'chunk_size' => null,
            'chunk_offsets' => null,
            'working_copy_path' => null,
            'processed_rows' => 0,
            'progress' => 0,
            'previewed_at' => null,
            'failure' => null,
            'preview_version' => max($session->preview_version, $current) + 1,
        ])->save();

        ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete();

        // Only once the reset is committed: a refused transition keeps the working copy.
        if ($workingCopy !== null) {
            $disk = $session->file_disk;
            $session->getConnection()->afterCommit(static fn () => Storage::disk($disk)->delete($workingCopy));
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
