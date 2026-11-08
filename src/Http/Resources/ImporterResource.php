<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Imports\DataTransferObjects\FieldDescriptor;
use RoundlyConsulting\Imports\DataTransferObjects\ImporterDescriptor;
use RoundlyConsulting\Imports\DataTransferObjects\OptionDescriptor;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\SourceFormat;

/**
 * An importer for a UI: key, label, fields (labels, types, allowed values, examples), modes.
 *
 * @property ImporterDescriptor $resource
 */
final class ImporterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'key' => $d->key,
            'label' => $d->label,
            'kind' => $d->kind->value,
            'fields' => array_map(static fn (FieldDescriptor $f): array => [
                'name' => $f->name,
                'label' => $f->label,
                'type' => $f->type->value,
                'required' => $f->required,
                'options' => array_map(static fn (OptionDescriptor $o): array => ['value' => $o->value, 'label' => $o->label], $f->options),
                'example' => $f->example,
                'help' => $f->help,
                'aliases' => $f->aliases,
                'format' => $f->format,
                'in_template' => $f->inTemplate,
            ], $d->fields),
            'modes' => array_map(static fn (DuplicateMode $m): array => ['value' => $m->value, 'label' => $m->label()], $d->modes),
            'default_mode' => $d->defaultMode?->value,
            'formats' => array_map(static fn (SourceFormat $f): string => $f->value, $d->formats),
            'extensions' => $d->extensions,
        ];
    }
}
