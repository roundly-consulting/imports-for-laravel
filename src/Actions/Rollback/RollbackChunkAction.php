<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Rollback;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;
use RoundlyConsulting\Imports\Enums\RollbackStatus;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Pipeline\Scoped;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Checksums;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\Failures;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\Keys;
use Throwable;

/**
 * Undo one keyset range of created records, each in its own transaction: loaded through the owner
 * scope, kept when it changed since the import, otherwise handed to the importer's rollback hook
 * (default: delete). The outcome is written to the ledger in the same transaction, so a retried
 * chunk touches nothing twice. A record that cannot be deleted (a host foreign key) is reported
 * `failed` and the rest carry on; infrastructure errors retry the chunk.
 *
 * @internal
 */
final readonly class RollbackChunkAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private OwnerScope $scope,
    ) {}

    public function execute(int|string $batchId, int|string $fromId, int|string $toId): void
    {
        $batch = ImportModels::batch()::query()->find($batchId);

        if ($batch === null) {
            return;
        }

        $session = $batch->session;
        $owner = Audits::owner($session);
        $definition = $this->registry->find($session->importer);
        $context = new ImportContext($owner, Audits::rollbackActor($batch), $definition, Keys::of($session), Keys::of($batch), $session->mode, $session->locale, $session->timezone, $this->scope);

        $this->scope->run($owner, function () use ($batch, $definition, $context, $fromId, $toId, $owner): void {
            if (! self::rollingBack($batch)) {
                return;
            }

            $ledgers = ImportModels::record()::query()
                ->where('batch_id', $batch->getKey())
                ->where('action', RecordAction::Created->value)
                ->whereNull('rolled_back_at')
                ->whereBetween('id', [$fromId, $toId])
                ->orderBy('id')
                ->get();

            foreach ($ledgers as $ledger) {
                $this->undo($batch, $definition, $context, $owner, $ledger);
            }
        });
    }

    private static function rollingBack(ImportBatch $batch): bool
    {
        return ImportModels::batch()::query()->whereKey($batch->getKey())->where('rollback_status', RollbackStatus::RollingBack->value)->exists();
    }

    private function undo(ImportBatch $batch, ImportDefinition $definition, ImportContext $context, Model $owner, ImportedRecord $ledger): void
    {
        $connection = $batch->getConnection();

        try {
            $connection->transaction(function () use ($batch, $definition, $context, $owner, $ledger): void {
                // Re-checked with the ledger row's lock, in the same query: a rollback the package
                // stopped meanwhile (failed, abandoned) never deletes another record.
                $fresh = ImportModels::record()::query()
                    ->whereKey($ledger->getKey())
                    ->whereIn('batch_id', ImportModels::batch()::query()->select('id')->whereKey($batch->getKey())->where('rollback_status', RollbackStatus::RollingBack->value))
                    ->lockForUpdate()
                    ->first();

                if ($fresh === null || $fresh->rolled_back_at !== null) {
                    return;
                }

                [$outcome, $reason] = $this->outcome($definition, $context, $owner, $fresh);

                $fresh->forceFill(['rolled_back_at' => Clock::now(), 'rollback_outcome' => $outcome, 'rollback_reason' => $reason])->save();
            });
        } catch (Throwable $exception) {
            if (Failures::isInfrastructure($exception)) {
                throw $exception;
            }

            report($exception);
            $ledger->forceFill(['rolled_back_at' => Clock::now(), 'rollback_outcome' => RollbackOutcome::Failed, 'rollback_reason' => mb_substr($exception->getMessage(), 0, 255)])->save();
        }
    }

    /**
     * @return array{0: RollbackOutcome, 1: string|null}
     */
    private function outcome(ImportDefinition $definition, ImportContext $context, Model $owner, ImportedRecord $ledger): array
    {
        $class = Relation::getMorphedModel($ledger->record_type) ?? $ledger->record_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return [RollbackOutcome::Missing, 'unknown_type'];
        }

        $record = Scoped::query($class, $owner, $definition->ownership(), $this->scope, true)->whereKey($ledger->record_id)->first();

        if ($record === null) {
            return [RollbackOutcome::Missing, null];
        }

        if (method_exists($record, 'trashed') && $record->trashed() === true) {
            return [RollbackOutcome::Missing, 'already_deleted'];
        }

        if ($this->changed($definition, $record, $ledger)) {
            return [RollbackOutcome::KeptChanged, null];
        }

        $outcome = $definition->rollback($record, $context);

        return [in_array($outcome, [RollbackOutcome::Deleted, RollbackOutcome::Handled, RollbackOutcome::Kept], true) ? $outcome : RollbackOutcome::Kept, null];
    }

    private function changed(ImportDefinition $definition, Model $record, ImportedRecord $ledger): bool
    {
        if ($definition->changeDetection()->usesChecksum()) {
            return $ledger->checksum !== null && Checksums::of($definition->checksumAttributes($record)) !== $ledger->checksum;
        }

        $column = $record->getUpdatedAtColumn();
        $raw = $column === null ? null : $record->getRawOriginal($column);

        if (! is_string($raw) || $raw === '' || $ledger->record_updated_at === null) {
            return false;
        }

        return Clock::utc(new CarbonImmutable($raw))->greaterThan($ledger->record_updated_at);
    }
}
