<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * The value types an importer field can have.
 */
#[TranslatedLabels('imports::enums.field_type')]
enum FieldType: string
{
    use Helpers;

    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Email = 'email';
    case Choice = 'choice';
    case Reference = 'reference';
}
