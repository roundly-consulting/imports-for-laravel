<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where the undo of a batch stands.
 */
#[TranslatedLabels('imports::enums.rollback_status')]
enum RollbackStatus: string
{
    use Helpers;

    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';
    case RollbackFailed = 'rollback_failed';
}
