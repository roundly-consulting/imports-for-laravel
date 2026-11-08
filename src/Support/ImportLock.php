<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportAlreadyRunningException;
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
use RoundlyConsulting\Imports\Models\ImportSession;

/**
 * One running commit or rollback per owner and importer: the session's `lock_key` column is
 * unique (NULLs are distinct on every engine), and acquiring it is one conditional update that also
 * moves the status — so a double-clicked commit starts one batch. The update runs in its own
 * savepoint inside a host transaction: on PostgreSQL a unique violation would otherwise abort it.
 *
 * @internal
 */
final class ImportLock
{
    /**
     * @param  list<SessionStatus>  $from
     */
    public static function acquire(ImportSession $session, array $from, SessionStatus $to, ?int $version = null): void
    {
        $key = Digests::lockKey($session->importer, Audits::owner($session));
        $now = Clock::now();

        $update = static function () use ($session, $from, $to, $version, $key, $now): int {
            $query = ImportModels::session()::query()
                ->whereKey($session->getKey())
                ->whereIn('status', array_map(static fn (SessionStatus $s): string => $s->value, $from))
                ->whereNull('lock_key');

            if ($version !== null) {
                $query->where('preview_version', $version);
            }

            return $query->update(['lock_key' => $key, 'status' => $to->value, 'last_progress_at' => $now, 'updated_at' => $now]);
        };

        $connection = $session->getConnection();

        try {
            $updated = $connection->transactionLevel() > 0 ? $connection->transaction($update) : $update();
        } catch (UniqueConstraintViolationException) {
            $running = ImportModels::session()::query()->where('lock_key', $key)->value('id');

            throw ImportAlreadyRunningException::forSession(is_scalar($running) ? (string) $running : null);
        }

        if ($updated === 0) {
            $fresh = ImportModels::session()::query()->whereKey($session->getKey())->first();

            if ($fresh !== null && $fresh->lock_key !== null && $fresh->lock_key === $key && $fresh->status !== $to) {
                throw ImportAlreadyRunningException::forSession((string) Keys::of($fresh));
            }

            $running = ImportModels::session()::query()->where('lock_key', $key)->whereKeyNot($session->getKey())->value('id');

            if ($running !== null) {
                throw ImportAlreadyRunningException::forSession(is_scalar($running) ? (string) $running : null);
            }

            throw $fresh !== null && $version !== null && $fresh->preview_version !== $version && in_array($fresh->status, $from, true)
                ? ImportStateException::stalePreview()
                : ImportStateException::wrongStatus(($fresh ?? $session)->status->value);
        }

        $session->refresh();
    }

    public static function release(int|string $sessionId): void
    {
        ImportModels::session()::query()->whereKey($sessionId)->update(['lock_key' => null]);
    }
}
