<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Commit;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSummary;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Events\ImportFailed;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Batches;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * The batch's `catch` (or a lost authorization): the commit stops, resumable from its next chunk
 * and undoable. Runs inside the owner's context; fires `ImportFailed` once.
 *
 * @internal
 */
final readonly class FailImportAction
{
    public function __construct(private OwnerScope $scope) {}

    public function execute(int|string $batchId, string $reason, ?string $message = null): void
    {
        $batch = ImportModels::batch()::query()->find($batchId);

        if ($batch === null || $batch->status !== BatchStatus::Running) {
            return;
        }

        $session = $batch->session;

        $this->scope->run(Audits::owner($session), static function () use ($batch, $session, $reason, $message): void {
            Batches::close($batch, BatchStatus::Failed, ['reason' => $reason, 'message' => $message === null ? null : mb_substr($message, 0, 500)]);

            Event::dispatch(new ImportFailed(Audits::for($session->refresh(), $batch, Audits::committer($batch)), $reason, ImportSummary::fromArray($batch->counts)));
        });
    }
}
