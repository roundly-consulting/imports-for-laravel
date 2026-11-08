<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\DuplicateMode;

/**
 * The preview a user reviews before committing. `updateNotice` is set whenever the commit would
 * update existing records: undo removes only what the import creates — updates are not reverted.
 */
final readonly class ImportPreview
{
    /**
     * @param  list<int>  $unmappedColumns
     */
    public function __construct(
        public ImportCounts $counts,
        public ?string $updateNotice,
        public DuplicateMode $mode,
        public array $unmappedColumns,
    ) {}
}
