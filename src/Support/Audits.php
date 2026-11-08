<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\DataTransferObjects\FileReference;
use RoundlyConsulting\Imports\DataTransferObjects\ImportAudit;
use RoundlyConsulting\Imports\DataTransferObjects\ImportCounts;
use RoundlyConsulting\Imports\Exceptions\ImportNotFoundException;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportSession;

/**
 * Builds the audit block every event carries.
 *
 * @internal
 */
final class Audits
{
    public static function for(ImportSession $session, ?ImportBatch $batch, ?Model $actor): ImportAudit
    {
        return new ImportAudit(
            sessionId: Keys::of($session),
            batchId: $batch === null ? null : Keys::of($batch),
            importer: $session->importer,
            owner: self::owner($session),
            actor: $actor,
            file: self::file($session),
            counts: ImportCounts::fromArray($session->counts),
            at: Clock::now(),
        );
    }

    public static function file(ImportSession $session): FileReference
    {
        return new FileReference($session->file_disk, $session->file_path, $session->file_name, $session->file_size, $session->file_sha256, $session->format);
    }

    public static function owner(ImportSession $session): Model
    {
        return self::ownerOrNull($session) ?? throw ImportNotFoundException::session();
    }

    /**
     * The owner, or null when it was deleted — housekeeping still closes such a session.
     */
    public static function ownerOrNull(ImportSession $session): ?Model
    {
        $owner = $session->relationLoaded('owner') ? $session->getRelation('owner') : $session->owner()->getResults();

        return $owner instanceof Model ? $owner : null;
    }

    public static function actor(ImportSession $session): ?Model
    {
        if ($session->actor_type === null) {
            return null;
        }

        $actor = $session->relationLoaded('actor') ? $session->getRelation('actor') : $session->actor()->getResults();

        return $actor instanceof Model ? $actor : null;
    }

    public static function committer(ImportBatch $batch): ?Model
    {
        if ($batch->actor_type === null) {
            return null;
        }

        $actor = $batch->actor()->getResults();

        return $actor instanceof Model ? $actor : null;
    }

    public static function rollbackActor(ImportBatch $batch): ?Model
    {
        if ($batch->rollback_actor_type === null) {
            return null;
        }

        $actor = $batch->rollbackActor()->getResults();

        return $actor instanceof Model ? $actor : null;
    }
}
