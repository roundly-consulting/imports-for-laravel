<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * One upload: the importer, the owner, the actor and the file, with the read options.
 */
final readonly class UploadData
{
    public function __construct(
        public string $importer,
        public Model $owner,
        public ?Model $actor,
        public ImportSource $source,
        public string|int|null $sheet = null,
        public ?string $delimiter = null,
        public int $headerRow = 1,
        public ?string $locale = null,
    ) {}
}
