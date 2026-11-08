<?php

declare(strict_types=1);

namespace Workbench\App;

use Closure;

/**
 * Test hooks into the workbench importers: a log of every callback call and an optional fault
 * injected into the create callback.
 */
final class Hooks
{
    /** @var list<string> */
    public static array $calls = [];

    public static ?Closure $onCreate = null;

    public static function call(string $what, int $row): void
    {
        self::$calls[] = $what.':'.$row;

        if ($what === 'create' && self::$onCreate !== null) {
            (self::$onCreate)($row);
        }
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$onCreate = null;
    }
}
