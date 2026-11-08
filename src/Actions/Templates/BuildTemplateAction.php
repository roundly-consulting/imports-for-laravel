<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Templates;

use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Templates\Template;
use RoundlyConsulting\Imports\Templates\TemplateBuilder;

/**
 * An importer's CSV or XLSX template, headers in the requested locale.
 */
final readonly class BuildTemplateAction
{
    public function __construct(private ImporterRegistry $registry) {}

    public function execute(string $importer, TemplateFormat $format, ?string $locale = null): Template
    {
        $locale ??= Locales::current();

        return Locales::using($locale, fn (): Template => TemplateBuilder::build($this->registry->rows($importer), $format, $locale));
    }
}
