<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Testing\ImportsFake;

/**
 * CSV and XLSX imports into Eloquent models: templates, column mapping, a validated preview,
 * duplicate handling, background batches and undo.
 *
 * @method static \RoundlyConsulting\Imports\Accessors\Importers importers()
 * @method static void register(string ...$classes)
 * @method static \RoundlyConsulting\Imports\Handles\ImporterHandle importer(string $key)
 * @method static \RoundlyConsulting\Imports\Templates\Template template(string $importer, \RoundlyConsulting\Imports\Enums\TemplateFormat|null $format = null, string|null $locale = null)
 * @method static \RoundlyConsulting\Imports\Handles\OwnerImports for(\Illuminate\Database\Eloquent\Model $owner)
 * @method static \RoundlyConsulting\Imports\Handles\SessionHandle session(\RoundlyConsulting\Imports\Models\ImportSession|int|string $session)
 * @method static \RoundlyConsulting\Imports\Handles\BatchHandle batch(\RoundlyConsulting\Imports\Models\ImportBatch|int|string $batch)
 * @method static \RoundlyConsulting\Imports\Reading\SpreadsheetFile read(\RoundlyConsulting\Imports\DataTransferObjects\ImportSource|\Illuminate\Http\UploadedFile|string $file, string|int|null $sheet = null, string|null $delimiter = null, int $headerRow = 1)
 * @method static \RoundlyConsulting\Imports\DataTransferObjects\PruneReport prune()
 * @method static \RoundlyConsulting\Imports\DataTransferObjects\SweepReport sweep()
 * @method static void assertUploaded(string|null $importer = null, \Closure|null $callback = null)
 * @method static void assertNothingUploaded()
 * @method static void assertFilesUploaded(string|null $importer = null, \Closure|null $callback = null)
 * @method static void assertNoFilesUploaded()
 * @method static void assertMapped(\Closure|null $callback = null)
 * @method static void assertNothingMapped()
 * @method static void assertMappingSaved(string|null $name = null)
 * @method static void assertNoMappingSaved()
 * @method static void assertMappingDeleted(string|null $name = null)
 * @method static void assertNoMappingDeleted()
 * @method static void assertModeChosen(\RoundlyConsulting\Imports\Enums\DuplicateMode|null $mode = null, \Closure|null $callback = null)
 * @method static void assertNoModeChosen()
 * @method static void assertSourceConfigured(\Closure|null $callback = null)
 * @method static void assertNoSourceConfigured()
 * @method static void assertPreviewed(\Closure|null $callback = null)
 * @method static void assertNothingPreviewed()
 * @method static void assertCommitted(string|null $importer = null, \Closure|null $callback = null)
 * @method static void assertNothingCommitted()
 * @method static void assertCancelled(\Closure|null $callback = null)
 * @method static void assertNothingCancelled()
 * @method static void assertResumed(\Closure|null $callback = null)
 * @method static void assertNothingResumed()
 * @method static void assertRolledBack(\Closure|null $callback = null)
 * @method static void assertNothingRolledBack()
 * @method static void assertDiscarded(\Closure|null $callback = null)
 * @method static void assertNothingDiscarded()
 * @method static void assertTemplateBuilt(string|null $importer = null, \RoundlyConsulting\Imports\Enums\TemplateFormat|null $format = null, string|null $locale = null)
 * @method static void assertNoTemplateBuilt()
 * @method static void assertErrorReportBuilt(\Closure|null $callback = null)
 * @method static void assertNoErrorReportBuilt()
 * @method static void assertPruned()
 * @method static void assertNotPruned()
 * @method static void assertSwept()
 * @method static void assertNotSwept()
 * @method static void assertNothingHappened()
 *
 * @see ImportsManager
 */
final class Imports extends Facade
{
    /**
     * Swap the manager for a recording fake — behind the facade and in the container, so an
     * injected ImportsManager is faked too. It passes calls through (handles keep returning real
     * sessions); add `Bus::fake()` to keep commits from running.
     */
    public static function fake(): ImportsFake
    {
        $fake = app(ImportsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ImportsManager::class;
    }
}
