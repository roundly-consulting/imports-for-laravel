<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Sessions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportDiscarded;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\SessionFiles;
use RoundlyConsulting\Imports\Support\SessionState;

/**
 * Throw an uncommitted session away: its file, working copy and row results are deleted; the
 * session row stays (soft-deleted) as history.
 */
final readonly class DiscardImportAction
{
    public function __construct(private ImporterRegistry $registry) {}

    public function execute(ImportSession $session, ?Model $actor): ImportSession
    {
        Access::check($this->registry->find($session->importer), $actor, Audits::owner($session), ImportOperation::Upload);
        SessionState::assertBeforeCommit($session);

        SessionFiles::delete($session);
        ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete();

        $session->forceFill([
            'status' => SessionStatus::Discarded,
            'preview_version' => $session->preview_version + 1,
            'working_copy_path' => null,
            'file_pruned_at' => Clock::now(),
        ])->save();
        $session->delete();

        Event::dispatch(new ImportDiscarded(Audits::for($session, null, $actor)));

        return $session;
    }
}
