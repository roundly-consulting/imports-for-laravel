<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Templates;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Support\TempFiles;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A generated template or error report: download it, store it on a disk, or take the temporary
 * file. The temp file is removed once it has been sent or stored.
 */
final class Template
{
    private bool $consumed = false;

    public function __construct(
        private readonly string $path,
        private readonly string $directory,
        public readonly TemplateFormat $format,
        public readonly string $filename,
    ) {}

    public function download(?string $filename = null): StreamedResponse
    {
        $filename ??= $this->filename;
        $path = $this->path;
        $directory = $this->directory;
        $this->consumed = true;

        $response = new StreamedResponse(static function () use ($path, $directory): void {
            $stream = fopen($path, 'rb');

            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }

            TempFiles::remove($directory);
        });

        $ascii = Str::ascii($filename);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $ascii);

        $response->headers->set('Content-Type', $this->format === TemplateFormat::Xlsx
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $ascii === '' ? 'template.'.$this->format->value : $ascii));
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function store(string $disk, string $path): string
    {
        $stream = fopen($this->path, 'rb');

        if ($stream === false || ! Storage::disk($disk)->writeStream($path, $stream)) {
            throw new RuntimeException('Cannot store the template.');
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->release();

        return $path;
    }

    /**
     * Move the file out of the package's temp area; the caller owns (and deletes) it.
     */
    public function toTemporaryFile(?string $target = null): string
    {
        $target ??= rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.Str::ulid().'.'.$this->format->value;

        if (! @copy($this->path, $target)) {
            throw new RuntimeException('Cannot copy the template.');
        }

        $this->release();

        return $target;
    }

    public function contents(): string
    {
        return (string) file_get_contents($this->path);
    }

    public function __destruct()
    {
        if (! $this->consumed) {
            $this->release();
        }
    }

    private function release(): void
    {
        $this->consumed = true;
        TempFiles::remove($this->directory);
    }
}
