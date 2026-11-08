<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Handles;

use RoundlyConsulting\Imports\DataTransferObjects\ImporterDescriptor;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Templates\Template;

/**
 * One importer: its descriptor, its template, its modes.
 */
final readonly class ImporterHandle
{
    public function __construct(
        private ImportsManager $manager,
        private string $key,
    ) {}

    public function descriptor(?string $locale = null): ImporterDescriptor
    {
        return $this->manager->container()->make(ImporterRegistry::class)->describe($this->key, $locale);
    }

    public function template(?TemplateFormat $format = null, ?string $locale = null): Template
    {
        return $this->manager->template($this->key, $format, $locale);
    }

    /**
     * @return list<DuplicateMode>
     */
    public function modes(): array
    {
        $definition = $this->manager->container()->make(ImporterRegistry::class)->find($this->key);

        return $definition instanceof Importer ? $definition->modes() : [];
    }
}
