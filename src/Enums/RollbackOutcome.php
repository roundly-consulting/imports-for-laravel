<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * What an undo did to one imported record (the first three are what a rollback hook may return).
 */
#[TranslatedLabels('imports::enums.rollback_outcome')]
enum RollbackOutcome: string
{
    use Helpers;

    case Deleted = 'deleted';
    case Handled = 'handled';
    case Kept = 'kept';
    case KeptChanged = 'kept_changed';
    case Missing = 'missing';
    case Failed = 'failed';
}
