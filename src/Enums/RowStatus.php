<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How the preview (or the commit) classified one data row. Plain valid rows are not stored.
 */
#[TranslatedLabels('imports::enums.row_status')]
enum RowStatus: string
{
    use Helpers;

    case Valid = 'valid';
    case Invalid = 'invalid';
    case DuplicateInFile = 'duplicate_in_file';
    case Matched = 'matched';
    case Failed = 'failed';
}
