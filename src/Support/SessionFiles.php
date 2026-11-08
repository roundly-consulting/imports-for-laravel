<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Definition\Limits;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Reading\ReadOptions;
use RoundlyConsulting\Imports\Reading\SpreadsheetFile;

/**
 * A session's files on the import disk: `imports/<owner digest>/<session id>/source.<ext>`, the
 * XLSX working copy beside it, file-batch entries under `entries/`. Never on a public disk.
 *
 * @internal
 */
final class SessionFiles
{
    public static function directory(string $ownerDigest, int|string $sessionId): string
    {
        return ImportsConfig::storagePath().'/'.$ownerDigest.'/'.$sessionId;
    }

    public static function disk(string $disk): Filesystem
    {
        return Storage::disk($disk);
    }

    /**
     * A local path to the session's source: the disk path itself on a local disk, else a copy in
     * `$tempDirectory`.
     */
    public static function localSource(ImportSession $session, string $tempDirectory): string
    {
        return self::local($session->file_disk, $session->file_path, $tempDirectory, 'source');
    }

    public static function localWorkingCopy(ImportSession $session, string $tempDirectory): string
    {
        if ($session->working_copy_path === null) {
            throw ImportStateException::filePruned();
        }

        return self::local($session->file_disk, $session->working_copy_path, $tempDirectory, 'working');
    }

    public static function local(string $disk, string $path, string $tempDirectory, string $name): string
    {
        $filesystem = Storage::disk($disk);

        if ($filesystem->missing($path)) {
            throw ImportStateException::filePruned();
        }

        if (config('filesystems.disks.'.$disk.'.driver') === 'local') {
            return $filesystem->path($path);
        }

        $target = $tempDirectory.DIRECTORY_SEPARATOR.$name;
        $stream = $filesystem->readStream($path);
        $out = @fopen($target, 'wb');

        if (! is_resource($stream) || $out === false) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        return $target;
    }

    public static function open(ImportSession $session, string $tempDirectory, Limits $limits): SpreadsheetFile
    {
        return SpreadsheetFile::open(self::localSource($session, $tempDirectory), ReadOptions::fromConfig(
            limits: $limits,
            sheet: $session->sheet,
            delimiter: $session->delimiter,
            headerRow: $session->header_row,
            timezone: $session->timezone,
        ));
    }

    public static function delete(ImportSession $session): void
    {
        self::deletePaths($session->file_disk, $session->file_path, $session->working_copy_path);
    }

    /**
     * The same, from paths read before the session row changed.
     */
    public static function deletePaths(string $disk, string $filePath, ?string $workingCopyPath): void
    {
        $filesystem = Storage::disk($disk);
        $directory = dirname($filePath);

        if (str_starts_with($directory, ImportsConfig::storagePath().'/')) {
            $filesystem->deleteDirectory($directory);
        } else {
            $filesystem->delete(array_values(array_filter([$filePath, $workingCopyPath])));
        }
    }
}
