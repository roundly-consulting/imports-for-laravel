<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The operations an importer's authorize() callback is asked about.
 */
#[TranslatedLabels('imports::enums.import_operation')]
enum ImportOperation: string
{
    use Helpers;

    case View = 'view';
    case Upload = 'upload';
    case Commit = 'commit';
    case Rollback = 'rollback';
}
