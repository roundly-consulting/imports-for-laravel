<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\DataTransferObjects\WrittenRow;
use RoundlyConsulting\Imports\Models\ImportBatch;

/**
 * Writes a chunk's ledger rows in slices of 500 — one insert per slice instead of one per row —
 * with the checksum of each created record already in place. Values go through the (swappable)
 * ledger model's casts and key, so the rows are exactly what Eloquent would store; the model's
 * own events do not fire (the import events are the hook).
 *
 * @internal
 */
final class LedgerWriter
{
    /**
     * @param  list<WrittenRow>  $written
     * @param  array<string, Model>  $fresh  created records as read back after the writes, by `type|key`
     * @param  callable(Model): string  $checksum
     */
    public static function insert(ImportBatch $batch, Model $owner, array $written, array $fresh, callable $checksum): void
    {
        $class = ImportModels::record();
        $now = Clock::now();
        $digest = Digests::owner($owner);
        $rows = [];

        foreach ($written as $row) {
            $record = $fresh[self::key($row->record)] ?? null;
            $updatedAt = $record === null || $record->getUpdatedAtColumn() === null ? null : $record->getRawOriginal($record->getUpdatedAtColumn());

            $ledger = new $class;
            $ledger->forceFill([
                'batch_id' => $batch->getKey(),
                'session_id' => $batch->session_id,
                'row_number' => $row->number,
                'record_type' => $row->record->getMorphClass(),
                'record_id' => $row->record->getKey(),
                'action' => $row->action,
                'checksum' => $record === null ? null : $checksum($record),
                'record_updated_at' => is_string($updatedAt) && $updatedAt !== '' ? new CarbonImmutable($updatedAt) : null,
                'owner_digest' => $digest,
                'content_hash' => $row->contentHash,
                'created_at' => $now,
            ]);

            if ($ledger->usesUniqueIds()) {
                $ledger->setAttribute($ledger->getKeyName(), $ledger->newUniqueId());
            }

            $rows[] = $ledger->getAttributes();
        }

        foreach (array_chunk($rows, 500) as $slice) {
            ImportModels::record()::query()->insert($slice);
        }
    }

    public static function key(Model $record): string
    {
        $key = $record->getKey();

        return $record->getMorphClass().'|'.(is_int($key) || is_string($key) ? (string) $key : '');
    }
}
