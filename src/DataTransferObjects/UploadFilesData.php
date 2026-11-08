<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * One file-batch upload: many files and/or ZIP archives for a file importer.
 */
final readonly class UploadFilesData
{
    /**
     * @param  list<UploadedFile>  $files
     */
    public function __construct(
        public string $importer,
        public Model $owner,
        public ?Model $actor,
        public array $files,
        public ?string $locale = null,
    ) {}
}
