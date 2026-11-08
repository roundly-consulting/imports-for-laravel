<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

/**
 * The checksum undo compares: scalars as strings, nulls kept, binary as hex, keys sorted — from a
 * fresh raw read on the same connection at import and at undo, so driver types match.
 *
 * @internal
 */
final class Checksums
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function of(array $attributes): string
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            $normalized[$key] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? '1' : '0',
                is_string($value) => mb_check_encoding($value, 'UTF-8') ? $value : bin2hex($value),
                is_scalar($value) => (string) $value,
                default => json_encode($value) ?: '',
            };
        }

        ksort($normalized);

        return Digests::hex(json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
