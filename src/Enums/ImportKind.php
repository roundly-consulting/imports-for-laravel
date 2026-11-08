<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Row imports (CSV/XLSX) or file batches (many files or a ZIP).
 */
#[TranslatedLabels('imports::enums.import_kind')]
enum ImportKind: string
{
    use Helpers;

    case Rows = 'rows';
    case Files = 'files';
}
