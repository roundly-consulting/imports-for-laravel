<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Commit;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\CellError;
use RoundlyConsulting\Imports\DataTransferObjects\ChunkOutcome;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSummary;
use RoundlyConsulting\Imports\DataTransferObjects\WrittenRow;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RecordAction;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Events\ImportChunkProcessed;
use RoundlyConsulting\Imports\Exceptions\ImportRowException;
use RoundlyConsulting\Imports\Exceptions\InvalidImporterDefinitionException;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Pipeline\InFileKeys;
use RoundlyConsulting\Imports\Pipeline\RowPipeline;
use RoundlyConsulting\Imports\Pipeline\Scoped;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportFile;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Checksums;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\Failures;
use RoundlyConsulting\Imports\Support\FileDuplicateCheck;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\KeyTypes;
use RoundlyConsulting\Imports\Support\LedgerWriter;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\SessionRows;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Templates\TemplateRows;
use Throwable;

/**
 * Commits one chunk in ONE transaction on the import connection: it opens by claiming the batch
 * row (`lockForUpdate()` + `status = running` + `chunks_completed === index`; anything else is a duplicate delivery or
 * a retry of a committed chunk → no work), then writes each row in its own savepoint together with
 * its ledger row, and finally moves the chunk counter. The host write, the ledger, the row results
 * and the counter commit or roll back together — a retry after a crash is a no-op or a clean redo.
 *
 * Rows the preview found invalid or duplicated are skipped; every other row is re-validated and
 * re-matched against the current data. A row error fails the row; an infrastructure error retries
 * the chunk.
 *
 * @internal
 */
final readonly class ProcessChunkAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private OwnerScope $scope,
        private ValidationFactory $validation,
        private Container $container,
    ) {}

    public function execute(int|string $batchId, int $index, bool $first = false, ?Batch $laravel = null): void
    {
        $batch = ImportModels::batch()::query()->find($batchId);

        if ($batch === null) {
            return;
        }

        $session = $batch->session;
        $owner = Audits::owner($session);
        $importer = $this->registry->find($session->importer);

        $this->scope->run($owner, function () use ($batch, $session, $owner, $importer, $index, $first, $laravel): void {
            if ($first && ! Access::allows($importer, Audits::committer($batch), $owner, ImportOperation::Commit)) {
                $laravel?->cancel();
                $this->container->make(FailImportAction::class)->execute(Keys::of($batch), 'unauthorized');

                return;
            }

            if ($importer instanceof FileImporter) {
                $outcome = $this->commitFiles($batch, $session, $owner, $importer, $index);
                $summary = $outcome->summary;
                $count = $outcome->rows;
            } elseif ($importer instanceof Importer) {
                $temp = TempFiles::directory();

                try {
                    $rows = iterator_to_array(SessionRows::chunk($session, $index, $temp, $importer->limits()->columns), true);
                    $summary = $this->commit($batch, $session, $owner, $importer, $index, $rows);
                    $count = count($rows);
                } finally {
                    TempFiles::remove($temp);
                }
            } else {
                return;
            }

            if ($summary !== null) {
                Event::dispatch(new ImportChunkProcessed(Audits::for($session, $batch->refresh(), Audits::committer($batch)), $index, $count, $summary));
            }
        });
    }

    /**
     * @param  array<int, list<Cell|null>>  $rows
     */
    private function commit(ImportBatch $batch, ImportSession $session, Model $owner, Importer $importer, int $index, array $rows): ?ImportSummary
    {
        $connection = $batch->getConnection();
        $work = function () use ($batch, $session, $owner, $importer, $index, $rows, $connection): ?ImportSummary {
            $claimed = ImportModels::batch()::query()->whereKey($batch->getKey())->lockForUpdate()->first();

            if (! self::claims($claimed, $index)) {
                return null;
            }

            $numbers = array_keys($rows);
            $stored = $numbers === [] ? [] : $this->stored($session, min($numbers), max($numbers));
            $counts = ['created' => 0, 'linked' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'reclassified' => 0, 'duplicates' => 0, 'invalid' => 0];
            $pending = [];

            foreach ($rows as $number => $cells) {
                $result = $stored[$number] ?? null;

                if ($result?->status === RowStatus::Invalid) {
                    $counts['invalid']++;
                } elseif ($result?->status === RowStatus::DuplicateInFile) {
                    $counts['duplicates']++;
                } else {
                    $pending[$number] = $cells;
                }
            }

            $pipeline = new RowPipeline($importer, SessionState::mapping($session)->toArray(), count($session->headers), $owner, $this->scope, $session->locale, $session->timezone, $this->validation);
            $context = new ImportContext($owner, Audits::committer($batch), $importer, Keys::of($session), Keys::of($batch), $session->mode, $session->locale, $session->timezone, $this->scope);
            $written = [];

            $window = TemplateRows::window($rows, $index * max(1, $session->chunk_size ?? 1));

            foreach ($pending === [] ? [] : $pipeline->evaluate($pending, new InFileKeys, $window) as $evaluation) {
                if ($evaluation->templateRow) {
                    continue;
                }

                $previous = isset($stored[$evaluation->number]) ? $stored[$evaluation->number]->status : RowStatus::Valid;

                if ($previous !== $evaluation->status && $previous !== RowStatus::Failed) {
                    $counts['reclassified']++;
                }

                if (! $evaluation->isValid()) {
                    $counts[$evaluation->status === RowStatus::DuplicateInFile ? 'duplicates' : 'failed']++;
                    $this->failRow($session, $evaluation->number, $evaluation->errors);

                    continue;
                }

                $plan = RowPipeline::outcome($evaluation->status, $session->mode);

                if ($plan === RowOutcome::Skip) {
                    $counts['skipped']++;

                    continue;
                }

                $row = $evaluation->row;
                $match = $evaluation->match;

                if ($row === null) {
                    continue;
                }

                $write = $this->write(
                    $connection, $session, $owner, $importer, $evaluation->number,
                    static fn (): Model => $plan === RowOutcome::Update && $match !== null ? $importer->update($match, $row, $context) : $importer->create($row, $context),
                    $plan === RowOutcome::Update,
                    null,
                );

                if ($write === null) {
                    $counts['failed']++;

                    continue;
                }

                $counts[$write->action->value]++;
                $written[] = $write;
            }

            return $this->complete($claimed, $session, $importer, $owner, $index, $counts, $written);
        };

        return $connection->transactionLevel() === 0 ? $connection->transaction($work, 3) : $connection->transaction($work);
    }

    /**
     * A file-batch chunk: every valid entry of the range becomes one record through the file
     * importer's callback; hashes are re-checked against the ledger first.
     */
    private function commitFiles(ImportBatch $batch, ImportSession $session, Model $owner, FileImporter $importer, int $index): ChunkOutcome
    {
        $connection = $batch->getConnection();
        $first = (int) (($session->chunk_offsets ?? [])[$index][1] ?? 0);
        $results = ImportModels::rowResult()::query()->where('session_id', $session->getKey())->where('row_number', '>=', $first)->orderBy('row_number')->limit($session->chunk_size ?? 1)->get();

        $work = function () use ($batch, $session, $owner, $importer, $index, $results, $connection): ?ImportSummary {
            $claimed = ImportModels::batch()::query()->whereKey($batch->getKey())->lockForUpdate()->first();

            if (! self::claims($claimed, $index)) {
                return null;
            }

            $context = new ImportContext($owner, Audits::committer($batch), $importer, Keys::of($session), Keys::of($batch), $session->mode, $session->locale, $session->timezone, $this->scope);
            $hashes = array_values(array_filter($results->where('status', RowStatus::Valid)->pluck('content_hash')->all(), 'is_string'));
            $earlier = FileDuplicateCheck::earlier($importer, $owner, $hashes, Keys::of($session));
            $counts = ['created' => 0, 'linked' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'reclassified' => 0, 'duplicates' => 0, 'invalid' => 0];
            $written = [];

            foreach ($results as $result) {
                if ($result->status === RowStatus::Invalid || $result->status === RowStatus::Failed) {
                    $counts['invalid']++;

                    continue;
                }

                if ($result->status !== RowStatus::Valid || isset($earlier[(string) $result->content_hash])) {
                    $counts['reclassified'] += $result->status === RowStatus::Valid ? 1 : 0;
                    $counts['duplicates']++;

                    continue;
                }

                $file = new ImportFile(
                    $result->row_number,
                    (string) $result->label,
                    strtolower(pathinfo((string) $result->label, PATHINFO_EXTENSION)),
                    (int) $result->file_size,
                    (string) $result->content_hash,
                    $session->file_disk,
                    (string) $result->stored_path,
                );

                $write = $this->write(
                    $connection, $session, $owner, $importer, $result->row_number,
                    static fn (): Model => $importer->create($file, $context),
                    false,
                    $result->content_hash,
                );

                if ($write === null) {
                    $counts['failed']++;

                    continue;
                }

                $counts[$write->action->value]++;
                $written[] = $write;
            }

            return $this->complete($claimed, $session, $importer, $owner, $index, $counts, $written);
        };

        $summary = $connection->transactionLevel() === 0 ? $connection->transaction($work, 3) : $connection->transaction($work);

        return new ChunkOutcome($summary, $results->count());
    }

    /**
     * Only a running batch at exactly this chunk does work: a failed, abandoned or cancelled batch
     * never writes again, whatever its leftover jobs do.
     *
     * @phpstan-assert-if-true ImportBatch $claimed
     */
    private static function claims(?ImportBatch $claimed, int $index): bool
    {
        return $claimed !== null && $claimed->status === BatchStatus::Running && $claimed->chunks_completed === $index;
    }

    /**
     * The chunk's ledger rows in bulk, after one fresh read of the records it created (so undo can
     * tell later edits apart), then the chunk counter — all inside the chunk transaction.
     *
     * @param  array<string, int>  $counts
     * @param  list<WrittenRow>  $written
     */
    private function complete(ImportBatch $claimed, ImportSession $session, ImportDefinition $importer, Model $owner, int $index, array $counts, array $written): ImportSummary
    {
        LedgerWriter::insert($claimed, $owner, $written, $this->fresh($importer, $owner, $written), static fn (Model $record): string => Checksums::of($importer->checksumAttributes($record)));

        $total = $claimed->counts;

        foreach ($counts as $key => $value) {
            $total[$key] = ($total[$key] ?? 0) + $value;
        }

        $claimed->forceFill(['counts' => $total, 'chunks_completed' => $index + 1])->save();
        ImportModels::session()::query()->whereKey($session->getKey())->update(['last_progress_at' => Clock::format(Clock::now())]);

        return ImportSummary::fromArray($total);
    }

    /**
     * One row in its own savepoint: the host callback and the ownership/connection/key checks. Its
     * ledger row is written with the chunk's (the claim excludes a second run; the unique
     * `(batch_id, row_number)` index stays the backstop). Null = the row failed.
     *
     * @param  Closure(): Model  $callback
     */
    private function write(Connection $connection, ImportSession $session, Model $owner, ImportDefinition $importer, int $number, Closure $callback, bool $update, ?string $contentHash): ?WrittenRow
    {
        try {
            return $connection->transaction(function () use ($session, $owner, $importer, $number, $callback, $update, $contentHash, $connection): WrittenRow {
                $record = $callback();

                if (! $record->exists) {
                    throw ImportRowException::because(Locales::line('imports::validation.unsaved', [], $session->locale));
                }

                if ($record->getConnection()->getName() !== $connection->getName()) {
                    throw ImportRowException::because(Locales::line('imports::validation.other_connection', [], $session->locale));
                }

                if (! $this->scope->owns($record, $owner, $importer->ownership())) {
                    throw ImportRowException::because(Locales::line('imports::validation.outside_owner', [], $session->locale));
                }

                KeyTypes::assert($record, 'record');

                $action = match (true) {
                    $update => RecordAction::Updated,
                    $record->wasRecentlyCreated => RecordAction::Created,
                    default => RecordAction::Linked,
                };

                return new WrittenRow($number, $action, $record, $contentHash);
            });
        } catch (Throwable $exception) {
            if (Failures::isInfrastructure($exception)) {
                throw $exception;
            }

            [$code, $message] = match (true) {
                $exception instanceof ImportRowException => ['callback_failed', $exception->getMessage()],
                $exception instanceof ValidationException => ['callback_failed', self::firstError($exception)],
                $exception instanceof UniqueConstraintViolationException => ['duplicate_created_meanwhile', Locales::line('imports::validation.duplicate_created_meanwhile', [], $session->locale)],
                default => ['callback_failed', Locales::line('imports::validation.callback_failed', [], $session->locale)],
            };

            if (! $exception instanceof ImportRowException && ! $exception instanceof ValidationException && ! $exception instanceof UniqueConstraintViolationException) {
                report($exception);
            }

            $this->failRow($session, $number, [CellError::row($code, $message)]);

            return null;
        }
    }

    /**
     * @param  list<CellError>  $errors
     */
    private function failRow(ImportSession $session, int $number, array $errors): void
    {
        ImportModels::rowResult()::query()->updateOrCreate(
            ['session_id' => $session->getKey(), 'row_number' => $number],
            ['status' => RowStatus::Failed, 'outcome' => null, 'errors' => CellError::storedList($errors)],
        );
    }

    private static function firstError(ValidationException $exception): string
    {
        foreach (Arr::flatten($exception->errors()) as $message) {
            if (is_string($message)) {
                return $message;
            }
        }

        return $exception->getMessage();
    }

    /**
     * @return array<int, ImportRowResult>
     */
    private function stored(ImportSession $session, int $from, int $to): array
    {
        $results = [];

        foreach (ImportModels::rowResult()::query()->where('session_id', $session->getKey())->whereBetween('row_number', [$from, $to])->get() as $result) {
            $results[$result->row_number] = $result;
        }

        return $results;
    }

    /**
     * One fresh read of the created records through the owner query, per 500. A created record the
     * owner query cannot see gets no checksum — undo then keeps it (`no_checksum`) — and is
     * reported, because the importer's ownership and its create() disagree.
     *
     * @param  list<WrittenRow>  $written
     * @return array<string, Model>
     */
    private function fresh(ImportDefinition $importer, Model $owner, array $written): array
    {
        $created = [];

        foreach ($written as $row) {
            if ($row->action === RecordAction::Created) {
                $created[$row->record::class][] = $row->record->getKey();
            }
        }

        $fresh = [];

        foreach ($created as $class => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (Scoped::query($class, $owner, $importer->ownership(), $this->scope, true)->whereKey($chunk)->get() as $record) {
                    $fresh[LedgerWriter::key($record)] = $record;
                }
            }
        }

        foreach ($written as $row) {
            if ($row->action === RecordAction::Created && ! isset($fresh[LedgerWriter::key($row->record)])) {
                report(InvalidImporterDefinitionException::because($importer->key(), sprintf('row %d created a record its ownership query cannot see; undo will keep it', $row->number)));
            }
        }

        return $fresh;
    }
}
