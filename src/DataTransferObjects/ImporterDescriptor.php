<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\SourceFormat;

/**
 * An importer, discoverable for a UI: its fields (labels, types, allowed values, examples), modes
 * and formats, in the requested locale.
 */
final readonly class ImporterDescriptor
{
    /**
     * @param  list<FieldDescriptor>  $fields
     * @param  list<DuplicateMode>  $modes
     * @param  list<SourceFormat>  $formats
     * @param  list<string>  $extensions  file importers: the accepted extensions
     */
    public function __construct(
        public string $key,
        public string $label,
        public ImportKind $kind,
        public array $fields,
        public array $modes,
        public ?DuplicateMode $defaultMode,
        public array $formats,
        public array $extensions = [],
    ) {}
}
