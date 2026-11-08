<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use RoundlyConsulting\Imports\Enums\FieldType;

/**
 * One importer field as a UI shows it, in the requested locale.
 */
final readonly class FieldDescriptor
{
    /**
     * @param  list<OptionDescriptor>  $options
     * @param  list<string>  $aliases
     */
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type,
        public bool $required,
        public array $options,
        public string $example,
        public ?string $help,
        public array $aliases,
        public ?string $format,
        public bool $inTemplate,
    ) {}
}
