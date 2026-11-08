<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Zip;

use php_user_filter;

/**
 * A read filter on a ZIP entry stream that counts the bytes really inflated and aborts the read
 * the moment a {@see ByteBudget} cap is crossed — a header that lies about its size is stopped
 * mid-stream, before the parser ever sees the rest.
 *
 * @internal
 */
final class GuardedStream extends php_user_filter
{
    public const string NAME = 'roundly-imports.guarded';

    private static bool $registered = false;

    public static function register(): void
    {
        if (! self::$registered) {
            self::$registered = in_array(self::NAME, stream_get_filters(), true) || stream_filter_register(self::NAME, self::class);
        }
    }

    /**
     * @param  resource  $stream
     */
    public static function attach($stream, ByteBudget $budget, int $compressedSize): void
    {
        self::register();
        $budget->startPart($compressedSize);
        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, $budget);
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $budget = $this->params;

        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($budget instanceof ByteBudget && ! $budget->consume($bucket->datalen)) {
                return PSFS_ERR_FATAL;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
