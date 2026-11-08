<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What happens to rows that match an existing record.
 */
#[TranslatedLabels('imports::enums.duplicate_mode')]
enum DuplicateMode: string
{
    use Helpers;

    case Skip = 'skip';
    case Update = 'update';
    case CreateNew = 'create_new';
}
