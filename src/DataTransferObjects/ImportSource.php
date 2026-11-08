<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;

/**
 * Where an import's file comes from: an upload, a local path, or a file on a Laravel disk (copied
 * to a local temp file first — an XLSX needs random access).
 */
final readonly class ImportSource
{
    private function __construct(
        public ?UploadedFile $upload = null,
        public ?string $path = null,
        public ?string $disk = null,
        public ?string $name = null,
    ) {}

    public static function upload(UploadedFile $file): self
    {
        return new self(upload: $file);
    }

    public static function path(string $path, ?string $name = null): self
    {
        return new self(path: $path, name: $name);
    }

    public static function disk(string $disk, string $path, ?string $name = null): self
    {
        return new self(path: $path, disk: $disk, name: $name);
    }

    public static function from(self|UploadedFile|string $file): self
    {
        return match (true) {
            $file instanceof self => $file,
            $file instanceof UploadedFile => self::upload($file),
            default => self::path($file),
        };
    }

    /**
     * The original file name (never trusted as a path).
     */
    public function originalName(): string
    {
        $name = $this->name ?? $this->upload?->getClientOriginalName() ?? basename((string) $this->path);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '_', mb_scrub($name, 'UTF-8')));

        return mb_substr($name === '' ? 'upload' : $name, 0, 255);
    }

    /**
     * The size the client or disk declares, checked before the file is read at all.
     */
    public function declaredSize(): ?int
    {
        if ($this->upload !== null) {
            $size = $this->upload->getSize();

            return is_int($size) ? $size : null;
        }

        if ($this->disk !== null && $this->path !== null) {
            return Storage::disk($this->disk)->size($this->path);
        }

        $size = $this->path === null ? false : @filesize($this->path);

        return $size === false ? null : $size;
    }

    /**
     * A local path to read; a disk file is copied into `$tempDirectory` first.
     */
    public function localPath(string $tempDirectory): string
    {
        if ($this->upload !== null) {
            $path = $this->upload->getRealPath();

            if ($path === false || ! is_file($path)) {
                throw ImportFileRejectedException::because(RejectionReason::Unreadable);
            }

            return $path;
        }

        if ($this->disk !== null && $this->path !== null) {
            $stream = Storage::disk($this->disk)->readStream($this->path);
            $target = $tempDirectory.DIRECTORY_SEPARATOR.'source';
            $out = @fopen($target, 'wb');

            if (! is_resource($stream) || $out === false) {
                throw ImportFileRejectedException::because(RejectionReason::Unreadable);
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);

            return $target;
        }

        if ($this->path === null || ! is_file($this->path)) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        return $this->path;
    }
}
