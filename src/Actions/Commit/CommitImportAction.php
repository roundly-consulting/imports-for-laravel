<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Commit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportStarted;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\Commits;
use RoundlyConsulting\Imports\Support\ImportLock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\KeyTypes;
use Throwable;

/**
 * Commit a previewed session: authorize, take the owner+importer lock (the same update moves the
 * session to `committing`, so a double-click starts one batch), record the committer and dispatch
 * one Laravel job batch of chunk jobs. Refused on a stale or expired preview.
 */
final readonly class CommitImportAction
{
    public function __construct(private ImporterRegistry $registry) {}

    public function execute(ImportSession $session, ?Model $actor): ImportBatch
    {
        $importer = $this->registry->find($session->importer);
        Access::check($importer, $actor, Audits::owner($session), ImportOperation::Commit);
        KeyTypes::assert($actor, 'actor');

        if ($session->status !== SessionStatus::Previewed) {
            throw ImportStateException::wrongStatus($session->status->value);
        }

        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            throw ImportStateException::expired();
        }

        ImportLock::acquire($session, [SessionStatus::Previewed], SessionStatus::Committing, $session->preview_version);

        try {
            /** @var ImportBatch $batch */
            $batch = ImportModels::batch()::query()->create([
                'session_id' => $session->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'status' => BatchStatus::Running,
                'chunk_size' => $session->chunk_size ?? 1,
                'total_chunks' => count($session->chunk_offsets ?? []),
                'chunks_completed' => 0,
                'counts' => [],
                'started_at' => Clock::now(),
            ]);
        } catch (Throwable $exception) {
            ImportLock::release(Keys::of($session));
            $session->forceFill(['status' => SessionStatus::Previewed])->save();

            throw $exception;
        }

        Event::dispatch(new ImportStarted(Audits::for($session, $batch, $actor)));
        Commits::dispatch($batch, 0);

        return $batch->refresh();
    }
}
