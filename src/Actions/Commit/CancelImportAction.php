<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Commit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Events\ImportCancelled;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Keys;

/**
 * Cancel a running commit. Rows already written stay (and can be undone); the remaining chunk jobs
 * return early and the batch's `then` releases the lock.
 */
final readonly class CancelImportAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private FinishImportAction $finish,
    ) {}

    public function execute(ImportBatch $batch, ?Model $actor): ImportBatch
    {
        $session = $batch->session;
        Access::check($this->registry->find($session->importer), $actor, Audits::owner($session), ImportOperation::Commit);

        if ($batch->status !== BatchStatus::Running) {
            throw ImportStateException::wrongStatus($batch->status->value);
        }

        $laravel = $batch->laravel_batch_id === null ? null : Bus::findBatch($batch->laravel_batch_id);

        if ($laravel === null || $laravel->finished()) {
            $this->finish->execute(Keys::of($batch), true);
        } else {
            $laravel->cancel();
        }

        Event::dispatch(new ImportCancelled(Audits::for($session, $batch, $actor)));

        return $batch->refresh();
    }
}
