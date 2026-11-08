<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where a suggested column mapping came from.
 */
#[TranslatedLabels('imports::enums.mapping_source')]
enum MappingSource: string
{
    use Helpers;

    case Saved = 'saved';
    case Name = 'name';
    case Label = 'label';
    case Alias = 'alias';
    case Fuzzy = 'fuzzy';
    case Manual = 'manual';
    case Suggested = 'suggested';
}
