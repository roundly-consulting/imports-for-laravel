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

    /**
     * Every operation the assets importer's authorize() was asked about.
     *
     * @var list<string>
     */
    public static array $authorized = [];

    /** Overrides the assets importer's authorize() when set: fn (string $operation): bool. */
    public static ?Closure $authorize = null;

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
        self::$authorized = [];
        self::$authorize = null;
        Importers\AssetsImporter::$changeDetection = null;
        Importers\DocumentsImporter::$duplicates = null;
    }
}
