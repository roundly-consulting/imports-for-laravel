<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case Retired = 'retired';
    case Repair = 'repair';
}
