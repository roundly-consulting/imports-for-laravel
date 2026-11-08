<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Crypto\Facades\Crypto;

/**
 * Every digest the package computes goes through crypto-for-laravel (sha-256), never a local
 * hash() call: owner digests, header signatures, lock keys, checksums and file hashes.
 *
 * @internal
 */
final class Digests
{
    public static function hex(string $data): string
    {
        return Crypto::digest()->hex($data);
    }

    /**
     * 32 raw bytes: the in-file duplicate keys, kept in memory per seen row.
     */
    public static function raw(string $data): string
    {
        return Crypto::digest()->raw($data);
    }

    public static function file(string $path): string
    {
        return Crypto::digest()->file($path);
    }

    /**
     * @param  resource  $stream
     */
    public static function stream($stream): string
    {
        return Crypto::digest()->stream($stream);
    }

    public static function owner(Model $owner): string
    {
        return self::hex($owner->getMorphClass()."\x1f".self::keyString($owner->getKey()));
    }

    public static function lockKey(string $importer, Model $owner): string
    {
        return self::hex('lock'."\x1f".$importer."\x1f".$owner->getMorphClass()."\x1f".self::keyString($owner->getKey()));
    }

    public static function keyString(mixed $key): string
    {
        return is_scalar($key) ? (string) $key : '';
    }
}
