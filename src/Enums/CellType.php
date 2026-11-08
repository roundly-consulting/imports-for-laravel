<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The type a spreadsheet cell carries.
 */
#[TranslatedLabels('imports::enums.cell_type')]
enum CellType: string
{
    use Helpers;

    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Error = 'error';
}
