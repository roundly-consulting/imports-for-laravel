<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports;

use Closure;
use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use LogicException;
use RoundlyConsulting\Imports\Accessors\Importers;
use RoundlyConsulting\Imports\Actions\Maintenance\PruneImportsAction;
use RoundlyConsulting\Imports\Actions\Maintenance\SweepImportsAction;
use RoundlyConsulting\Imports\Actions\Templates\BuildTemplateAction;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSource;
use RoundlyConsulting\Imports\DataTransferObjects\PruneReport;
use RoundlyConsulting\Imports\DataTransferObjects\SweepReport;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Exceptions\ImportNotFoundException;
use RoundlyConsulting\Imports\Handles\BatchHandle;
use RoundlyConsulting\Imports\Handles\ImporterHandle;
use RoundlyConsulting\Imports\Handles\OwnerImports;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Reading\ReadOptions;
use RoundlyConsulting\Imports\Reading\SpreadsheetFile;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Templates\Template;

/**
 * The package's public API — the root behind the Imports facade, injectable by its own
 * class-string. Every method is thin: it resolves an action through the container (so host
 * overrides and the fake apply) or hands back a handle whose mutations come back through
 * {@see self::perform()}.
 *
 * Not final on purpose: ImportsFake extends it, so code that injects this class still type-checks
 * under `Imports::fake()`. Holds no container: under Octane every call resolves from the current
 * one.
 */
class ImportsManager
{
    /**
     * The app's importers: list, find, describe for a UI, filter by who may view them.
     */
    public function importers(): Importers
    {
        return new Importers($this->container()->make(ImporterRegistry::class));
    }

    /**
     * Register importers at runtime (beside `config('imports.importers')`).
     *
     * @param  class-string<ImportDefinition>  ...$classes
     */
    public function register(string ...$classes): void
    {
        $this->container()->make(ImporterRegistry::class)->register(...$classes);
    }

    public function importer(string $key): ImporterHandle
    {
        $this->container()->make(ImporterRegistry::class)->find($key);

        return new ImporterHandle($this, $key);
    }

    /**
     * An importer's CSV or XLSX template, headers in `$locale`.
     */
    public function template(string $importer, ?TemplateFormat $format = null, ?string $locale = null): Template
    {
        $format ??= ImportsConfig::templateDefaultFormat();

        return $this->perform(
            'template',
            BuildTemplateAction::class,
            static fn (BuildTemplateAction $action): Template => $action->execute($importer, $format, $locale),
            ['importer' => $importer, 'format' => $format, 'locale' => $locale],
        ) ?? throw new LogicException('The template was not built.');
    }

    /**
     * Everything one owner imports — the scoped, safe entry point for HTTP code.
     */
    public function for(Model $owner): OwnerImports
    {
        return new OwnerImports($this, $owner);
    }

    /**
     * A session by model or id — the trusted path, with no owner check. Inside HTTP code use
     * `Imports::for($owner)->session($id)`.
     */
    public function session(ImportSession|int|string $session): SessionHandle
    {
        $model = $session instanceof ImportSession ? $session : ((Keys::accepts($session) ? ImportModels::session()::query()->find($session) : null) ?? throw ImportNotFoundException::session());

        return new SessionHandle($this, $model);
    }

    /**
     * A batch by model or id — the trusted path, with no owner check.
     */
    public function batch(ImportBatch|int|string $batch): BatchHandle
    {
        $model = $batch instanceof ImportBatch ? $batch : ((Keys::accepts($batch) ? ImportModels::batch()::query()->find($batch) : null) ?? throw ImportNotFoundException::batch());

        return new BatchHandle($this, $model);
    }

    /**
     * Read any CSV or XLSX file as a stream of rows — the importer pipeline's own dependency-free
     * reader, no session involved. Close it when done.
     */
    public function read(ImportSource|UploadedFile|string $file, string|int|null $sheet = null, ?string $delimiter = null, int $headerRow = 1): SpreadsheetFile
    {
        $source = ImportSource::from($file);
        // Only a disk source is copied; it lands in a temp directory imports:sweep clears.
        $temp = $source->disk === null ? sys_get_temp_dir() : TempFiles::directory();

        return SpreadsheetFile::open($source->localPath($temp), ReadOptions::fromConfig(sheet: $sheet, delimiter: $delimiter, headerRow: $headerRow));
    }

    public function prune(): PruneReport
    {
        return $this->perform('prune', PruneImportsAction::class, static fn (PruneImportsAction $action): PruneReport => $action->execute())
            ?? new PruneReport;
    }

    public function sweep(): SweepReport
    {
        return $this->perform('sweep', SweepImportsAction::class, static fn (SweepImportsAction $action): SweepReport => $action->execute())
            ?? new SweepReport;
    }

    /**
     * @internal
     */
    public function container(): Container
    {
        return CurrentContainer::getInstance();
    }

    /**
     * Run one operation. Every handle and accessor funnels its terminal call through here,
     * resolving the action from the container, so host overrides apply and the fake records the
     * call. The fake may return null for operations it does not run.
     *
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult|null
     */
    public function perform(string $operation, string $action, Closure $execute, array $context = []): mixed
    {
        $instance = $this->container()->make($action);

        if (! $instance instanceof $action) {
            throw new LogicException(sprintf('The container resolved [%s] to another class.', class_basename($action)));
        }

        return $execute($instance);
    }
}
