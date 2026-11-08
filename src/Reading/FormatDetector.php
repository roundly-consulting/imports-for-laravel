<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Reading;

use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Zip\ZipGuard;

/**
 * Decides the format by content — magic bytes and the OPC content types — never by extension or
 * client MIME type, and names the real format of everything it refuses.
 *
 * @internal
 */
final class FormatDetector
{
    public static function detect(string $path, int $maxBytes, bool $allowMacroEnabled = false): SourceFormat
    {
        $size = @filesize($path);

        if ($size === false) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        if ($size === 0) {
            throw ImportFileRejectedException::because(RejectionReason::Empty);
        }

        if ($size > $maxBytes) {
            throw ImportFileRejectedException::because(RejectionReason::TooLarge, [
                'limit' => self::kilobytes($maxBytes),
                'actual' => self::kilobytes($size),
            ]);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        $head = (string) fread($handle, 65536);
        fclose($handle);

        return match (true) {
            str_starts_with($head, "PK\x03\x04") => self::container($path, $allowMacroEnabled),
            str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => throw ImportFileRejectedException::because(RejectionReason::LegacyOrEncrypted),
            str_starts_with($head, "\xFF\xFE\x00\x00"), str_starts_with($head, "\x00\x00\xFE\xFF") => throw ImportFileRejectedException::because(RejectionReason::Utf32),
            str_starts_with($head, "\xFF\xFE"), str_starts_with($head, "\xFE\xFF") => throw ImportFileRejectedException::because(RejectionReason::Utf16),
            str_contains($head, "\0") => throw ImportFileRejectedException::because(RejectionReason::Binary),
            default => SourceFormat::Csv,
        };
    }

    private static function container(string $path, bool $allowMacroEnabled): SourceFormat
    {
        $zip = ZipGuard::open($path, RejectionReason::UnsupportedFormat);

        try {
            $mimetype = $zip->getFromName('mimetype', 256);

            if (is_string($mimetype) && str_starts_with(trim($mimetype), 'application/vnd.oasis.opendocument')) {
                throw ImportFileRejectedException::because(RejectionReason::OpenDocument);
            }

            $types = $zip->getFromName('[Content_Types].xml', 1_048_576);

            if (! is_string($types)) {
                throw ImportFileRejectedException::because(RejectionReason::UnsupportedFormat);
            }

            return match (true) {
                str_contains($types, 'spreadsheetml.sheet.main+xml') => SourceFormat::Xlsx,
                str_contains($types, 'sheet.macroEnabled.main+xml') => $allowMacroEnabled
                    ? SourceFormat::Xlsx
                    : throw ImportFileRejectedException::because(RejectionReason::MacroEnabled),
                str_contains($types, 'sheet.binary.macroEnabled.main') => throw ImportFileRejectedException::because(RejectionReason::Xlsb),
                default => throw ImportFileRejectedException::because(RejectionReason::UnsupportedFormat),
            };
        } finally {
            @$zip->close();
        }
    }

    private static function kilobytes(int $bytes): string
    {
        return number_format($bytes / 1024, 0, '.', ' ').' KB';
    }
}
