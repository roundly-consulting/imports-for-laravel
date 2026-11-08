<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Commit;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSummary;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Events\ImportCompleted;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Batches;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * The batch's `then`: every chunk ran (or returned early after a cancel). Runs inside the owner's
 * context — Laravel calls it in the last job's process, whose context is the dispatcher's again.
 *
 * @internal
 */
final readonly class FinishImportAction
{
    public function __construct(private OwnerScope $scope) {}

    public function execute(int|string $batchId, bool $cancelled): void
    {
        $batch = ImportModels::batch()::query()->find($batchId);

        if ($batch === null || $batch->status !== BatchStatus::Running) {
            return;
        }

        $session = $batch->session;

        $this->scope->run(Audits::owner($session), static function () use ($batch, $session, $cancelled): void {
            Batches::close($batch, $cancelled ? BatchStatus::Cancelled : BatchStatus::Completed);

            if (! $cancelled) {
                $started = $batch->started_at;
                $duration = $started === null ? null : (int) $started->diffInSeconds($batch->finished_at, true);

                Event::dispatch(new ImportCompleted(Audits::for($session->refresh(), $batch, Audits::committer($batch)), ImportSummary::fromArray($batch->counts, $duration)));
            }
        });
    }
}
