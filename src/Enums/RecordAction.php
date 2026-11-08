<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What a commit did to one record: created it, linked an existing one a create callback returned, or updated a matched one.
 */
#[TranslatedLabels('imports::enums.record_action')]
enum RecordAction: string
{
    use Helpers;

    case Created = 'created';
    case Linked = 'linked';
    case Updated = 'updated';
}
