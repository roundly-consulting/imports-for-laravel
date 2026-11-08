<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Rollback;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportRollbackFailed;
use RoundlyConsulting\Imports\Events\ImportRolledBack;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Batches;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\RollbackReports;

/**
 * The end of an undo: the report from the ledger, statuses, the lock released, the event —
 * inside the owner's context.
 *
 * @internal
 */
final readonly class FinishRollbackAction
{
    public function __construct(private OwnerScope $scope) {}

    public function execute(int|string $batchId, ?string $failure = null): void
    {
        $batch = ImportModels::batch()::query()->find($batchId);

        if ($batch === null || $batch->rollback_status !== RollbackStatus::RollingBack) {
            return;
        }

        $session = $batch->session;

        if ($failure !== null) {
            Batches::cancel($batch->rollback_batch_id);
        }

        $this->scope->run(Audits::owner($session), static function () use ($batch, $session, $failure): void {
            $report = RollbackReports::build($batch);
            $now = Clock::now();

            $batch->forceFill([
                'rollback_status' => $failure === null ? RollbackStatus::RolledBack : RollbackStatus::RollbackFailed,
                'rollback_counts' => [
                    'deleted' => $report->deleted,
                    'handled' => $report->handled,
                    'kept_changed' => $report->keptChanged,
                    'kept' => $report->keptByHook,
                    'linked' => $report->linked,
                    'missing' => $report->missing,
                    'failed' => $report->failed,
                ],
                'rolled_back_at' => $failure === null ? $now : null,
            ])->save();

            ImportModels::session()::query()->whereKey($session->getKey())->update([
                'status' => ($failure === null ? SessionStatus::RolledBack : SessionStatus::RollbackFailed)->value,
                'lock_key' => null,
                'last_progress_at' => Clock::format($now),
                'updated_at' => Clock::format($now),
            ]);

            $audit = Audits::for($session->refresh(), $batch, Audits::rollbackActor($batch));

            Event::dispatch($failure === null ? new ImportRolledBack($audit, $report) : new ImportRollbackFailed($audit, $failure));
        });
    }
}
