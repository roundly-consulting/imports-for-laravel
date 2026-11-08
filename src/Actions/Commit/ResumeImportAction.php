<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Commit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Events\ImportResumed;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Commits;
use RoundlyConsulting\Imports\Support\ImportLock;

/**
 * Resume a failed or cancelled commit from its next chunk (a new Laravel batch). Refused once the
 * source file (or the XLSX working copy) has been pruned.
 */
final readonly class ResumeImportAction
{
    public function __construct(private ImporterRegistry $registry) {}

    public function execute(ImportBatch $batch, ?Model $actor): ImportBatch
    {
        $batch->refresh();
        $session = $batch->session;
        Access::check($this->registry->find($session->importer), $actor, Audits::owner($session), ImportOperation::Commit);

        if (! in_array($batch->status, [BatchStatus::Failed, BatchStatus::Cancelled], true) || $batch->rollback_status !== null) {
            throw ImportStateException::wrongStatus($batch->status->value);
        }

        $disk = Storage::disk($session->file_disk);

        if ($session->file_pruned_at !== null || $disk->missing($session->file_path)
            || ($session->format === SourceFormat::Xlsx && ($session->working_copy_path === null || $disk->missing($session->working_copy_path)))) {
            throw ImportStateException::filePruned();
        }

        ImportLock::acquire($session, [SessionStatus::Failed, SessionStatus::Cancelled], SessionStatus::Committing);

        $batch->forceFill([
            'status' => BatchStatus::Running,
            'failure' => null,
            'finished_at' => null,
            'undo_until' => null,
            'actor_type' => $actor?->getMorphClass() ?? $batch->actor_type,
            'actor_id' => $actor?->getKey() ?? $batch->actor_id,
        ])->save();

        Event::dispatch(new ImportResumed(Audits::for($session, $batch, $actor)));
        Commits::dispatch($batch, $batch->chunks_completed);

        return $batch->refresh();
    }
}
