<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Carbon\CarbonInterval;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * One local temp directory per operation (`<tmp_path>/<pid>-<ulid>`): a disk source copied for
 * random access, the writer's parts, a ZIP entry being staged. The action or job that made it
 * removes it in a `finally`; a crash leaves it to `imports:sweep` after `storage.tmp_ttl`.
 *
 * @internal
 */
final class TempFiles
{
    public static function directory(): string
    {
        $root = ImportsConfig::tmpPath();

        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException(sprintf('Cannot create the imports temp directory [%s].', $root));
        }

        $directory = $root.DIRECTORY_SEPARATOR.getmypid().'-'.Str::ulid();

        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the imports temp directory [%s].', $directory));
        }

        return $directory;
    }

    public static function remove(?string $directory): void
    {
        if ($directory === null || ! is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * Delete operation directories older than the TTL (a crashed worker's leftovers).
     */
    public static function sweep(CarbonInterval $ttl): int
    {
        $root = ImportsConfig::tmpPath();

        if (! is_dir($root)) {
            return 0;
        }

        $cutoff = time() - Durations::seconds($ttl);
        $removed = 0;

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! $entry instanceof SplFileInfo || ! $entry->isDir() || preg_match('/^\d+-[0-9A-HJKMNP-TV-Z]{26}$/', $entry->getFilename()) !== 1) {
                continue;
            }

            if ($entry->getMTime() < $cutoff) {
                self::remove($entry->getPathname());
                $removed++;
            }
        }

        return $removed;
    }
}
