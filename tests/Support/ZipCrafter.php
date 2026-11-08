<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use ZipArchive;

/**
 * Hostile archives: names that climb out of the extract directory, symlinks, encrypted entries,
 * bombs, and entries whose headers lie about their size (both the local header and the central
 * directory patched, so the consistency check passes and only the streaming count catches it).
 */
final class ZipCrafter
{
    /**
     * @param  array<string, string>  $entries
     */
    public static function make(array $entries, ?callable $configure = null): string
    {
        return XlsxBuilder::zip($entries, $configure);
    }

    public static function symlink(string $name, string $target): string
    {
        return self::make([$name => $target, 'ok.txt' => 'fine'], static function (ZipArchive $zip) use ($name): void {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
    }

    public static function encrypted(): string
    {
        return self::make(['secret.txt' => 'top secret'], static function (ZipArchive $zip): void {
            $zip->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, 'password');
        });
    }

    /**
     * Highly compressible content: `$bytes` of zeros in one entry.
     */
    public static function bomb(int $bytes, string $name = 'bomb.txt'): string
    {
        return self::make([$name => str_repeat("\0", $bytes)]);
    }

    /**
     * An archive whose entry declares `$declared` uncompressed bytes in both headers while its
     * deflate stream really inflates to the full content.
     */
    public static function lyingSize(string $name, string $content, int $declared): string
    {
        $path = self::make([$name => $content]);
        $bytes = (string) file_get_contents($path);
        $actual = strlen($content);

        // Local file header: signature PK\3\4, uncompressed size at offset 22.
        $local = strpos($bytes, "PK\x03\x04");
        $bytes = substr_replace($bytes, pack('V', $declared), $local + 22, 4);

        // Central directory header: signature PK\1\2, uncompressed size at offset 24.
        $central = strpos($bytes, "PK\x01\x02");
        $bytes = substr_replace($bytes, pack('V', $declared), $central + 24, 4);

        file_put_contents($path, $bytes);

        return $actual > 0 ? $path : $path;
    }
}
