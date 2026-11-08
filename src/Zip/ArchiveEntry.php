<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Zip;

/**
 * One file entry of an uploaded archive, as its central directory declares it.
 *
 * @internal
 */
final readonly class ArchiveEntry
{
    public function __construct(
        public int $index,
        public string $name,
        public int $size,
        public int $compressedSize,
    ) {}
}
