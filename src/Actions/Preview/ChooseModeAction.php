<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Preview;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportModeNotSupportedException;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\SessionState;

/**
 * Pick what happens to rows that match an existing record: skip, update or create new. On a
 * previewed session the rows are re-planned from their stored matches (no re-read); the preview
 * version moves, so a commit against the old plan is refused.
 */
final readonly class ChooseModeAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private ClassifyRowsAction $classify,
    ) {}

    public function execute(ImportSession $session, DuplicateMode $mode, ?Model $actor): ImportSession
    {
        $importer = $this->registry->rows($session->importer);
        Access::check($importer, $actor, Audits::owner($session), ImportOperation::Upload);
        SessionState::assertBeforeCommit($session);

        if ($session->status === SessionStatus::Validating) {
            throw ImportStateException::wrongStatus($session->status->value);
        }

        if (! in_array($mode, $importer->modes(), true)) {
            throw ImportModeNotSupportedException::mode($mode->value, $importer->key());
        }

        if ($session->status === SessionStatus::Previewed) {
            $counts = $this->classify->execute($session, $mode);
            $session->forceFill(['mode' => $mode, 'counts' => $counts->toArray(), 'preview_version' => $session->preview_version + 1])->save();

            return $session;
        }

        $session->forceFill(['mode' => $mode])->save();

        return $session;
    }
}
