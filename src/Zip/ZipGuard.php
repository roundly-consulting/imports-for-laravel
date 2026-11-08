<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Zip;

use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use ZipArchive;

/**
 * Every check a ZIP container gets before anything is read from it, shared by the XLSX reader and
 * the file-batch mode: opened read-only with a local-vs-central header consistency check, entry
 * count, encryption, declared sizes, names and symlinks — then streaming caps on the real bytes.
 *
 * @internal
 */
final class ZipGuard
{
    private const int S_IFMT = 0o170000;

    private const int S_IFLNK = 0o120000;

    public static function open(string $path, RejectionReason $onFailure): ZipArchive
    {
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS);

        if ($opened !== true) {
            throw ImportFileRejectedException::because($opened === ZipArchive::ER_INCONS ? RejectionReason::ZipInconsistent : $onFailure);
        }

        return $zip;
    }

    /**
     * The XLSX container: few enough entries, none encrypted, none declaring more than a part may
     * inflate to.
     */
    public static function inspectWorkbook(ZipArchive $zip, int $maxEntries, int $maxPartSize): void
    {
        if ($zip->numFiles > $maxEntries) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
            }

            if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw ImportFileRejectedException::because(RejectionReason::LegacyOrEncrypted);
            }

            if ($stat['size'] > $maxPartSize) {
                throw ImportFileRejectedException::because(RejectionReason::ZipTooLarge);
            }
        }
    }

    /**
     * An uploaded archive: every file entry, after the count, total, per-entry, ratio, name,
     * symlink and encryption checks on what the central directory declares.
     *
     * @param  list<string>  $ignore
     * @return list<ArchiveEntry>
     */
    public static function inspectArchive(
        ZipArchive $zip,
        int $maxFiles,
        int $maxUncompressed,
        int $maxEntrySize,
        int $maxRatio,
        int $ratioMinSize,
        array $ignore,
    ): array {
        if ($zip->numFiles > $maxFiles) {
            throw ImportFileRejectedException::because(RejectionReason::TooManyFiles, ['limit' => $maxFiles, 'actual' => $zip->numFiles]);
        }

        $entries = [];
        $total = 0;
        $compressedTotal = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);

            if ($stat === false) {
                throw ImportFileRejectedException::because(RejectionReason::ZipInconsistent);
            }

            $name = $stat['name'];

            if (! EntryName::isSafe($name)) {
                throw ImportFileRejectedException::because(RejectionReason::ZipUnsafePath, ['name' => self::printable($name)]);
            }

            $opsys = 0;
            $attributes = 0;

            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX
                && is_int($attributes) && (($attributes >> 16) & self::S_IFMT) === self::S_IFLNK) {
                throw ImportFileRejectedException::because(RejectionReason::ZipSymlink, ['name' => self::printable($name)]);
            }

            if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw ImportFileRejectedException::because(RejectionReason::ZipEncrypted, ['name' => self::printable($name)]);
            }

            if (str_ends_with(str_replace('\\', '/', $name), '/') || EntryName::ignored($name, $ignore)) {
                continue;
            }

            if ($stat['size'] > $maxEntrySize) {
                throw ImportFileRejectedException::because(RejectionReason::ZipEntryTooLarge, ['name' => self::printable($name)]);
            }

            if ($stat['size'] > $ratioMinSize && intdiv($stat['size'], max(1, $stat['comp_size'])) > $maxRatio) {
                throw ImportFileRejectedException::because(RejectionReason::ZipRatio);
            }

            $total += $stat['size'];
            $compressedTotal += $stat['comp_size'];

            if ($total > $maxUncompressed) {
                throw ImportFileRejectedException::because(RejectionReason::ZipTooLarge);
            }

            $entries[] = new ArchiveEntry($index, $name, $stat['size'], $stat['comp_size']);
        }

        if ($total > $ratioMinSize && intdiv($total, max(1, $compressedTotal)) > $maxRatio) {
            throw ImportFileRejectedException::because(RejectionReason::ZipRatio);
        }

        return $entries;
    }

    /**
     * Stream one entry to `$target`, counting the real inflated bytes against the budget — a
     * header that lies about the size is stopped here, mid-stream.
     */
    public static function extract(ZipArchive $zip, ArchiveEntry $entry, string $target, ByteBudget $budget): void
    {
        $stream = $zip->getStreamIndex($entry->index);
        $out = $stream === false ? false : @fopen($target, 'wb');

        if ($stream === false || $out === false) {
            throw ImportFileRejectedException::because(RejectionReason::ZipInconsistent);
        }

        $budget->startPart($entry->compressedSize);

        try {
            while (! feof($stream)) {
                $chunk = @fread($stream, 65536);

                if ($chunk === false) {
                    throw ImportFileRejectedException::because(RejectionReason::ZipInconsistent);
                }

                if (! $budget->consume(strlen($chunk))) {
                    throw ImportFileRejectedException::because(
                        $budget->part > $budget->perPart ? RejectionReason::ZipEntryTooLarge : ($budget->used > $budget->total ? RejectionReason::ZipTooLarge : RejectionReason::ZipRatio),
                        ['name' => self::printable($entry->name)],
                    );
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($stream);
            fclose($out);
        }
    }

    private static function printable(string $name): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', '?', mb_scrub($name, 'UTF-8')), 0, 120);
    }
}
