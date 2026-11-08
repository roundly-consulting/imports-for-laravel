<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Handles;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Actions\Commit\CancelImportAction;
use RoundlyConsulting\Imports\Actions\Commit\ResumeImportAction;
use RoundlyConsulting\Imports\Actions\Rollback\RollbackImportAction;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSummary;
use RoundlyConsulting\Imports\DataTransferObjects\Progress;
use RoundlyConsulting\Imports\DataTransferObjects\RollbackReport;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\RollbackReports;
use RoundlyConsulting\Imports\Support\Verdicts;

/**
 * One commit run: progress, summary, the records it wrote, cancel, resume and undo.
 *
 * Every read asks the importer's authorize() for `View` (once per handle), every write its own
 * operation. `model()` and `id()` are plain accessors.
 */
final readonly class BatchHandle
{
    private Verdicts $verdicts;

    public function __construct(
        private ImportsManager $manager,
        private ImportBatch $batch,
        private ?Model $actor = null,
        ?Verdicts $verdicts = null,
    ) {
        $this->verdicts = $verdicts ?? new Verdicts;
    }

    public function by(Authenticatable|Model|null $actor): self
    {
        return new self($this->manager, $this->batch, $actor instanceof Model ? $actor : null);
    }

    public function model(): ImportBatch
    {
        return $this->batch;
    }

    public function id(): int|string
    {
        return Keys::of($this->batch);
    }

    public function session(): SessionHandle
    {
        $this->view();

        return new SessionHandle($this->manager, $this->batch->session, $this->actor, $this->verdicts);
    }

    public function status(): BatchStatus
    {
        $this->view();

        return $this->batch->refresh()->status;
    }

    public function progress(): Progress
    {
        $this->view();
        $batch = $this->batch->refresh();

        if ($batch->rollback_status === RollbackStatus::RollingBack || $batch->rollback_status === RollbackStatus::RolledBack) {
            $total = ImportModels::record()::query()->where('batch_id', $batch->getKey())->where('action', RecordAction::Created->value)->count();
            $done = ImportModels::record()::query()->where('batch_id', $batch->getKey())->where('action', RecordAction::Created->value)->whereNotNull('rolled_back_at')->count();

            return new Progress($done, $total, $total === 0 ? 100 : intdiv($done * 100, $total), 'rollback');
        }

        $total = max(0, $batch->total_chunks);

        return new Progress($batch->chunks_completed, $total, $total === 0 ? 100 : intdiv($batch->chunks_completed * 100, $total), 'commit');
    }

    public function summary(): ImportSummary
    {
        $this->view();
        $batch = $this->batch->refresh();
        $duration = $batch->started_at === null ? null : (int) $batch->started_at->diffInSeconds($batch->finished_at ?? Clock::now(), true);

        return ImportSummary::fromArray($batch->counts, $duration);
    }

    /**
     * The ledger rows: what this batch created, linked or updated.
     *
     * @return Builder<ImportedRecord>
     */
    public function records(?RecordAction $action = null): Builder
    {
        $this->view();
        $query = ImportModels::record()::query()->where('batch_id', $this->batch->getKey())->orderBy('row_number');

        return $action === null ? $query : $query->where('action', $action->value);
    }

    public function cancel(): self
    {
        $this->manager->perform('cancel', CancelImportAction::class, fn (CancelImportAction $action): ImportBatch => $action->execute($this->batch, $this->actor), ['batch' => $this->batch]);

        return $this;
    }

    public function resume(): self
    {
        $this->manager->perform('resume', ResumeImportAction::class, fn (ResumeImportAction $action): ImportBatch => $action->execute($this->batch, $this->actor), ['batch' => $this->batch]);

        return $this;
    }

    /**
     * Undo the batch (within `undo.window`): created records unchanged since the import are
     * removed, changed ones kept, updates never reverted.
     */
    public function rollback(): self
    {
        $this->manager->perform('rollback', RollbackImportAction::class, fn (RollbackImportAction $action): ImportBatch => $action->execute($this->batch, $this->actor), ['batch' => $this->batch]);

        return $this;
    }

    public function canRollback(): bool
    {
        $this->view();
        $batch = $this->batch->refresh();

        return in_array($batch->status, [BatchStatus::Completed, BatchStatus::Failed, BatchStatus::Cancelled], true)
            && ($batch->rollback_status === null || $batch->rollback_status === RollbackStatus::RollbackFailed)
            && $batch->undo_until !== null
            && Clock::now()->lessThan($batch->undo_until);
    }

    /**
     * The undo report once a rollback finished (null while none ran or one is still running).
     */
    public function rollbackReport(): ?RollbackReport
    {
        $this->view();
        $batch = $this->batch->refresh();

        return $batch->rollback_status === RollbackStatus::RolledBack || $batch->rollback_status === RollbackStatus::RollbackFailed
            ? RollbackReports::build($batch)
            : null;
    }

    private function view(): void
    {
        $this->verdicts->check(ImportOperation::View, function (): bool {
            $session = $this->batch->session;

            return Access::allows($this->manager->container()->make(ImporterRegistry::class)->find($session->importer), $this->actor, Audits::owner($session), ImportOperation::View);
        });
    }
}
