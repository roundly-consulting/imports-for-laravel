<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The file formats the readers understand (decided by content, never by extension).
 */
#[TranslatedLabels('imports::enums.source_format')]
enum SourceFormat: string
{
    use Helpers;

    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
