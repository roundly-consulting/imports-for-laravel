<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Handles;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use RoundlyConsulting\Imports\Actions\Commit\CommitImportAction;
use RoundlyConsulting\Imports\Actions\Mapping\ApplyMappingAction;
use RoundlyConsulting\Imports\Actions\Mapping\SaveMappingAction;
use RoundlyConsulting\Imports\Actions\Mapping\SuggestMappingAction;
use RoundlyConsulting\Imports\Actions\Preview\BuildErrorReportAction;
use RoundlyConsulting\Imports\Actions\Preview\ChooseModeAction;
use RoundlyConsulting\Imports\Actions\Preview\PreviewImportAction;
use RoundlyConsulting\Imports\Actions\Sessions\ConfigureSourceAction;
use RoundlyConsulting\Imports\Actions\Sessions\DiscardImportAction;
use RoundlyConsulting\Imports\DataTransferObjects\ColumnMapping;
use RoundlyConsulting\Imports\DataTransferObjects\ImportCounts;
use RoundlyConsulting\Imports\DataTransferObjects\ImportPreview;
use RoundlyConsulting\Imports\DataTransferObjects\MappingSuggestion;
use RoundlyConsulting\Imports\DataTransferObjects\Progress;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\DataTransferObjects\SheetInfo;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\Verdicts;
use RoundlyConsulting\Imports\Templates\Template;

/**
 * One import session: source, mapping, mode, preview, commit. Mutators return the handle, so the
 * flow chains: `->map([...])->saveMapping('ERP')->mode(DuplicateMode::Update)->preview()`.
 *
 * Every operation asks the importer's authorize() about the handle's actor: reads as `View`
 * (asked once per handle), writes as their own operation. A `null` actor is system code, and the
 * host's authorize() decides what it may do. `model()` and `id()` are plain accessors.
 */
final readonly class SessionHandle
{
    private Verdicts $verdicts;

    public function __construct(
        private ImportsManager $manager,
        private ImportSession $session,
        private ?Model $actor = null,
        ?Verdicts $verdicts = null,
    ) {
        $this->verdicts = $verdicts ?? new Verdicts;
    }

    /**
     * The same session, acting as `$actor` (authorization is checked per operation).
     */
    public function by(Authenticatable|Model|null $actor): self
    {
        return new self($this->manager, $this->session, $actor instanceof Model ? $actor : null);
    }

    public function model(): ImportSession
    {
        return $this->session;
    }

    public function id(): int|string
    {
        return Keys::of($this->session);
    }

    public function status(): SessionStatus
    {
        $this->authorize(ImportOperation::View);

        return $this->session->refresh()->status;
    }

    /**
     * @return list<SheetInfo>
     */
    public function sheets(): array
    {
        $this->authorize(ImportOperation::View);

        return array_map(static fn (array $sheet): SheetInfo => new SheetInfo($sheet['name'], $sheet['index'], $sheet['visible']), $this->session->sheets ?? []);
    }

    /**
     * The header row as a UI shows it (a blank header is `Column C`).
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $this->authorize(ImportOperation::View);

        return SessionState::displayHeaders($this->session);
    }

    public function useSheet(string|int $sheet): self
    {
        return $this->configure($sheet, null, null);
    }

    public function useDelimiter(string $delimiter): self
    {
        return $this->configure(null, $delimiter, null);
    }

    public function useHeaderRow(int $row): self
    {
        return $this->configure(null, null, $row);
    }

    public function suggestMapping(): MappingSuggestion
    {
        return $this->manager->container()->make(SuggestMappingAction::class)->execute($this->session, $this->actor);
    }

    /**
     * @param  array<int|string, string|null>|ColumnMapping  $mapping  header text or column index => field
     */
    public function map(array|ColumnMapping $mapping): self
    {
        $this->manager->perform('map', ApplyMappingAction::class, fn (ApplyMappingAction $action): ImportSession => $action->execute($this->session, $mapping, $this->actor), ['session' => $this->session]);

        return $this;
    }

    public function saveMapping(string $name): self
    {
        $this->manager->perform('saveMapping', SaveMappingAction::class, fn (SaveMappingAction $action): mixed => $action->execute($this->session, $name, $this->actor), ['session' => $this->session, 'owner' => Audits::owner($this->session), 'importer' => $this->session->importer, 'name' => $name]);

        return $this;
    }

    public function mode(DuplicateMode $mode): self
    {
        $this->manager->perform('mode', ChooseModeAction::class, fn (ChooseModeAction $action): ImportSession => $action->execute($this->session, $mode, $this->actor), ['session' => $this->session, 'mode' => $mode]);

        return $this;
    }

    /**
     * Validate every row — in the request up to `preview.sync_threshold` rows, otherwise on the
     * queue (`true` / `false` forces either).
     */
    public function preview(?bool $queue = null): self
    {
        $this->manager->perform('preview', PreviewImportAction::class, fn (PreviewImportAction $action): ImportSession => $action->execute($this->session, $this->actor, $queue), ['session' => $this->session]);

        return $this;
    }

    public function counts(): ImportCounts
    {
        $this->authorize(ImportOperation::View);

        return ImportCounts::fromArray($this->session->refresh()->counts);
    }

    public function previewData(): ImportPreview
    {
        $this->authorize(ImportOperation::View);
        $counts = $this->counts();
        $mapped = array_map('intval', array_keys($this->session->mapping ?? []));

        return new ImportPreview(
            $counts,
            $counts->updates > 0 ? trans_choice('imports::preview.update_notice', $counts->updates, ['count' => $counts->updates], $this->session->locale) : null,
            $this->session->mode,
            array_values(array_diff(array_keys($this->session->headers), $mapped)),
        );
    }

    public function progress(): Progress
    {
        $this->authorize(ImportOperation::View);
        $session = $this->session->refresh();
        $batch = $session->batch;

        if ($batch !== null && $session->status === SessionStatus::Committing) {
            return new BatchHandle($this->manager, $batch, $this->actor, $this->verdicts)->progress();
        }

        return new Progress($session->processed_rows, $session->data_rows, $session->progress, 'preview');
    }

    public function rows(?RowFilter $filter = null): RowQuery
    {
        $this->authorize(ImportOperation::View);

        return new RowQuery($this->manager, $this->session, $filter ?? RowFilter::all());
    }

    /**
     * The failing rows plus an "Errors" column, ready to fix and re-upload.
     */
    public function errorReport(TemplateFormat $format = TemplateFormat::Xlsx): Template
    {
        return $this->manager->perform('errorReport', BuildErrorReportAction::class, fn (BuildErrorReportAction $action): Template => $action->execute($this->session, $format, $this->actor), ['session' => $this->session, 'format' => $format])
            ?? throw new LogicException('The error report was not built.');
    }

    public function commit(): BatchHandle
    {
        $batch = $this->manager->perform('commit', CommitImportAction::class, fn (CommitImportAction $action): ImportBatch => $action->execute($this->session, $this->actor), ['session' => $this->session, 'importer' => $this->session->importer]);

        return new BatchHandle($this->manager, $batch instanceof ImportBatch ? $batch : ($this->session->batch ?? throw new LogicException('No batch.')), $this->actor);
    }

    /**
     * Cancel the session's running commit.
     */
    public function cancel(): self
    {
        $this->batch()?->cancel();

        return $this;
    }

    public function discard(): self
    {
        $this->manager->perform('discard', DiscardImportAction::class, fn (DiscardImportAction $action): ImportSession => $action->execute($this->session, $this->actor), ['session' => $this->session]);

        return $this;
    }

    public function batch(): ?BatchHandle
    {
        $this->authorize(ImportOperation::View);
        $batch = $this->session->batch()->first();

        return $batch === null ? null : new BatchHandle($this->manager, $batch, $this->actor, $this->verdicts);
    }

    private function configure(string|int|null $sheet, ?string $delimiter, ?int $headerRow): self
    {
        $this->manager->perform('configureSource', ConfigureSourceAction::class, fn (ConfigureSourceAction $action): ImportSession => $action->execute($this->session, $this->actor, $sheet, $delimiter, $headerRow), ['session' => $this->session]);

        return $this;
    }

    private function authorize(ImportOperation $operation): void
    {
        $this->verdicts->check($operation, fn (): bool => Access::allows(
            $this->manager->container()->make(ImporterRegistry::class)->find($this->session->importer),
            $this->actor,
            Audits::owner($this->session),
            $operation,
        ));
    }
}
