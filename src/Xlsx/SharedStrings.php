<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use XMLReader;

/**
 * The shared-strings table, spooled instead of held in memory: one streaming pass appends each
 * string length-prefixed to a `php://temp` data stream and its offset (8 bytes) to an index
 * stream, both spilling to disk past `shared_strings_memory`. A lookup is two seeks, behind a
 * small LRU — memory stays flat whatever the string count.
 *
 * @internal
 */
final class SharedStrings
{
    private const int CACHE = 2048;

    /** @var resource|null */
    private $data;

    /** @var resource|null */
    private $index;

    private int $count = 0;

    /** @var array<int, string> */
    private array $cache = [];

    private function __construct(int $memory)
    {
        $data = fopen('php://temp/maxmemory:'.$memory, 'w+b');
        $index = fopen('php://temp/maxmemory:'.$memory, 'w+b');

        if ($data === false || $index === false) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        $this->data = $data;
        $this->index = $index;
    }

    public static function read(Package $package, ?string $part, int $memory): self
    {
        $strings = new self($memory);
        $reader = $part === null ? null : $package->xml($part);

        if ($reader === null) {
            return $strings;
        }

        while ($package->read($reader)) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $strings->append(RichText::read($package, $reader));
            }
        }

        $reader->close();
        $package->assertHealthy();

        return $strings;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function get(int $index): string
    {
        if (isset($this->cache[$index])) {
            $value = $this->cache[$index];
            unset($this->cache[$index]);

            return $this->cache[$index] = $value;
        }

        if ($index < 0 || $index >= $this->count || $this->data === null || $this->index === null) {
            throw ImportFileRejectedException::because(RejectionReason::MalformedSpreadsheet);
        }

        fseek($this->index, $index * 8);
        $offset = unpack('J', (string) fread($this->index, 8));
        fseek($this->data, is_array($offset) && is_int($offset[1] ?? null) ? $offset[1] : 0);
        $length = unpack('N', (string) fread($this->data, 4));
        $size = is_array($length) && is_int($length[1] ?? null) ? $length[1] : 0;
        $value = $size > 0 ? (string) fread($this->data, $size) : '';

        if (count($this->cache) >= self::CACHE) {
            unset($this->cache[array_key_first($this->cache)]);
        }

        return $this->cache[$index] = $value;
    }

    public function close(): void
    {
        foreach ([$this->data, $this->index] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->data = $this->index = null;
        $this->cache = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    private function append(string $value): void
    {
        if ($this->data === null || $this->index === null) {
            return;
        }

        fseek($this->data, 0, SEEK_END);
        $offset = (int) ftell($this->data);
        fwrite($this->data, pack('N', strlen($value)).$value);
        fseek($this->index, 0, SEEK_END);
        fwrite($this->index, pack('J', $offset));
        $this->count++;
    }
}
