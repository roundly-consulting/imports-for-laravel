<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\SourceFormat;

/**
 * Where an import's source file is: disk + path, original name, size, SHA-256 and format.
 */
final readonly class FileReference
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public int $size,
        public ?string $sha256,
        public ?SourceFormat $format,
    ) {}
}
