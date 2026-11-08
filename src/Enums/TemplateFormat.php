<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The formats a template or error report can be written in.
 */
#[TranslatedLabels('imports::enums.template_format')]
enum TemplateFormat: string
{
    use Helpers;

    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
