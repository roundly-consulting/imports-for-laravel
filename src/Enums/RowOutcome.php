<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What the commit will do with a row under the session's duplicate mode.
 */
#[TranslatedLabels('imports::enums.row_outcome')]
enum RowOutcome: string
{
    use Helpers;

    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';
}
