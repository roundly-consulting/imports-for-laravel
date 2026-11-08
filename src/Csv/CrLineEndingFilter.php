<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Csv;

use php_user_filter;

/**
 * Old Mac CSVs end lines with a lone CR, which `fgetcsv()` does not split on (and the
 * `auto_detect_line_endings` switch is deprecated). Maps CR to LF byte for byte, so offsets stay
 * valid. Appended only after a seek: a filtered stream's buffer makes `fseek` unreliable.
 *
 * @internal
 */
final class CrLineEndingFilter extends php_user_filter
{
    public const string NAME = 'roundly-imports.cr';

    private static bool $registered = false;

    /**
     * @param  resource  $stream
     */
    public static function attach($stream): void
    {
        if (! self::$registered) {
            self::$registered = in_array(self::NAME, stream_get_filters(), true) || stream_filter_register(self::NAME, self::class);
        }

        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ);
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = str_replace("\r", "\n", $bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
