<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Csv;

use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;

/**
 * A full streaming UTF-8 check of a CSV, in 64 KB blocks with an incomplete trailing sequence
 * carried into the next block. Pure ASCII passes. The first invalid byte rejects the file with its
 * line number and a guess at the real encoding, so the message can say what to do.
 *
 * @internal
 */
final class EncodingGuard
{
    private const int BLOCK = 65536;

    /**
     * @return bool whether the file starts with a UTF-8 BOM
     */
    public static function check(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        try {
            $bom = fread($handle, 3) === "\xEF\xBB\xBF";

            if (! $bom) {
                rewind($handle);
            }

            $carry = '';
            $lines = 0;

            while (! feof($handle)) {
                $block = fread($handle, self::BLOCK);

                if ($block === false) {
                    break;
                }

                $data = $carry.$block;
                $carry = '';

                if (! feof($handle)) {
                    $tail = self::incompleteTail($data);

                    if ($tail > 0) {
                        $carry = substr($data, -$tail);
                        $data = substr($data, 0, -$tail);
                    }
                }

                if (! mb_check_encoding($data, 'UTF-8')) {
                    $offset = self::firstInvalid($data);

                    throw ImportFileRejectedException::because(RejectionReason::NotUtf8, [
                        'line' => $lines + substr_count(substr($data, 0, $offset), "\n") + 1,
                        'guess' => self::guess($data),
                    ]);
                }

                $lines += substr_count($data, "\n");
            }

            if ($carry !== '' && ! mb_check_encoding($carry, 'UTF-8')) {
                throw ImportFileRejectedException::because(RejectionReason::NotUtf8, ['line' => $lines + 1, 'guess' => self::guess($carry)]);
            }

            return $bom;
        } finally {
            fclose($handle);
        }
    }

    /**
     * How many bytes at the end of `$data` start a multibyte sequence the block cut short.
     */
    private static function incompleteTail(string $data): int
    {
        $length = strlen($data);

        for ($back = 1; $back <= min(3, $length); $back++) {
            $byte = ord($data[$length - $back]);

            if (($byte & 0xC0) === 0x80) {
                continue;               // continuation byte: keep looking for the lead
            }

            $needed = match (true) {
                ($byte & 0xE0) === 0xC0 => 2,
                ($byte & 0xF0) === 0xE0 => 3,
                ($byte & 0xF8) === 0xF0 => 4,
                default => 1,
            };

            return $needed > $back ? $back : 0;
        }

        return 0;
    }

    private static function firstInvalid(string $data): int
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $byte = ord($data[$offset]);
            $size = match (true) {
                $byte < 0x80 => 1,
                ($byte & 0xE0) === 0xC0 => 2,
                ($byte & 0xF0) === 0xE0 => 3,
                ($byte & 0xF8) === 0xF0 => 4,
                default => 0,
            };

            if ($size === 0 || ! mb_check_encoding(substr($data, $offset, $size), 'UTF-8')) {
                return $offset;
            }

            $offset += $size;
        }

        return $length;
    }

    private static function guess(string $data): string
    {
        $key = match (true) {
            preg_match('/[\x8A\x8D\x8E\x9A\x9D\x9E]/', $data) === 1 => 'windows-1250',
            preg_match('/[\x80-\x9F]/', $data) === 1 => 'windows-1252',
            default => 'iso-8859',
        };

        return __('imports::rejections.encodings.'.$key);
    }
}
