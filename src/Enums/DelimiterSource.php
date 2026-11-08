<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * How a CSV delimiter was decided.
 */
#[TranslatedLabels('imports::enums.delimiter_source')]
enum DelimiterSource: string
{
    use Helpers;

    case Sniffed = 'sniffed';
    case Hint = 'hint';
    case Override = 'override';
}
