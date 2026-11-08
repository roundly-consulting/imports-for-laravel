<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * One allowed value of a choice field, with its label in the requested locale.
 */
final readonly class OptionDescriptor
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
