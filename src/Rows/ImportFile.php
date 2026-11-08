<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Rows;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One file of a file batch, handed to a file importer's create callback. Its name is the
 * sanitised original (metadata only — it never touches a path); the bytes sit on the import disk.
 */
final readonly class ImportFile
{
    public function __construct(
        public int $index,
        public string $name,
        public string $extension,
        public int $size,
        public string $sha256,
        private string $disk,
        private string $storedPath,
    ) {}

    /**
     * @return resource
     */
    public function readStream()
    {
        $stream = Storage::disk($this->disk)->readStream($this->storedPath);

        if (! is_resource($stream)) {
            throw new RuntimeException('The staged file is gone.');
        }

        return $stream;
    }

    /**
     * Copy the file to `$disk` under a generated name (never the original name) and return the path.
     * Not covered by the commit transaction: a rolled-back chunk can leave the copy behind, so pair
     * it with a rollback hook or a cleanup if that matters.
     */
    public function storeOn(string $disk, ?string $directory = null): string
    {
        $path = trim(($directory ?? '').'/'.Str::ulid().($this->extension === '' ? '' : '.'.$this->extension), '/');
        $stream = $this->readStream();

        try {
            Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    /**
     * The file's contents (small files only).
     */
    public function contents(): string
    {
        return (string) Storage::disk($this->disk)->get($this->storedPath);
    }
}
