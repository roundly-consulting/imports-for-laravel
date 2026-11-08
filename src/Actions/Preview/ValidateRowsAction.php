<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Preview;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\CellError;
use RoundlyConsulting\Imports\DataTransferObjects\ImportCounts;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Events\ImportPreviewed;
use RoundlyConsulting\Imports\Events\ImportPreviewFailed;
use RoundlyConsulting\Imports\Events\ImportPreviewProgressed;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Pipeline\InFileKeys;
use RoundlyConsulting\Imports\Pipeline\RowEvaluation;
use RoundlyConsulting\Imports\Pipeline\RowPipeline;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\RowResults;
use RoundlyConsulting\Imports\Support\SessionFiles;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Support\WorkingCopy;
use RoundlyConsulting\Imports\Templates\TemplateRows;
use Throwable;

/**
 * The preview pass: every data row, window by window, through the row pipeline. Writes only the
 * exceptions (invalid, duplicate, matched rows) — no row values — plus the counts, the commit
 * chunk offsets and, for XLSX, the working copy. Nothing is written to host tables and no
 * importer callback runs. Every write is guarded by `preview_version`: row results are inserted
 * only while the session row — locked for the check — still holds this run's version, the working
 * copy is this version's own file, and the final update is a compare-and-set. A run whose version
 * went stale (the mapping changed meanwhile) stops without writing into the newer preview.
 *
 * @internal
 */
final readonly class ValidateRowsAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private OwnerScope $scope,
        private ValidationFactory $validation,
    ) {}

    public function execute(int|string $sessionId, int $version): void
    {
        $session = ImportModels::session()::query()->find($sessionId);

        if ($session === null || $session->preview_version !== $version || $session->status !== SessionStatus::Validating) {
            return;
        }

        $owner = Audits::owner($session);

        $this->scope->run($owner, function () use ($session, $version, $owner): void {
            try {
                $this->run($session, $version, $owner);
            } catch (Throwable $exception) {
                $failed = ImportModels::session()::query()
                    ->whereKey($session->getKey())
                    ->where('preview_version', $version)
                    ->update(['status' => SessionStatus::PreviewFailed->value, 'failure' => json_encode(['message' => mb_substr($exception->getMessage(), 0, 500)]), 'updated_at' => Clock::format(Clock::now())]);

                if ($failed > 0) {
                    Event::dispatch(new ImportPreviewFailed(Audits::for($session->refresh(), null, Audits::actor($session)), mb_substr($exception->getMessage(), 0, 500)));
                }

                throw $exception;
            }
        });
    }

    private function run(ImportSession $session, int $version, Model $owner): void
    {
        $importer = $this->registry->rows($session->importer);
        $mapping = SessionState::mapping($session)->toArray();
        $pipeline = new RowPipeline($importer, $mapping, count($session->headers), $owner, $this->scope, $session->locale, $session->timezone, $this->validation);
        $chunkSize = max(ImportsConfig::chunkSize(), (int) ceil(max(1, $session->data_rows) / ImportsConfig::maxChunks()));
        $window = ImportsConfig::previewWindow();
        $progressEvery = ImportsConfig::progressEvery();
        $temp = TempFiles::directory();
        $file = null;
        $working = null;
        $workingLocal = null;

        try {
            if (! $this->guarded($session, $version, static function () use ($session): void {
                ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete();
            })) {
                return;
            }

            $file = SessionFiles::open($session, $temp, $importer->limits());

            if ($session->format === SourceFormat::Xlsx) {
                $workingLocal = $temp.DIRECTORY_SEPARATOR.'working.csv';
                $working = new WorkingCopy($workingLocal);
            }

            $seen = new InFileKeys;
            $counts = ['valid' => 0, 'invalid' => 0, 'duplicates_in_file' => 0, 'matched' => 0, 'template_rows' => 0, 'blank_rows' => 0, 'creates' => 0, 'updates' => 0, 'skips' => 0];
            $offsets = [];
            $buffer = [];
            $processed = 0;
            $previous = $session->header_row;

            foreach ($file->rows() as $number => $cells) {
                $counts['blank_rows'] += max(0, $number - $previous - 1);
                $previous = $number;
                $offset = $working !== null ? $working->write($number, $cells) : $file->lastOffset();

                if ($processed % $chunkSize === 0) {
                    $offsets[] = [(int) $offset, $number];
                }

                $buffer[$number] = $cells;
                $processed++;

                if (count($buffer) >= $window) {
                    if (! $this->window($session, $version, $pipeline, $buffer, $processed - count($buffer), $seen, $counts)) {
                        return;
                    }

                    $buffer = [];
                }

                if ($processed % $progressEvery === 0 && ! $this->progress($session, $version, $processed)) {
                    return;
                }
            }

            if ($buffer !== [] && ! $this->window($session, $version, $pipeline, $buffer, $processed - count($buffer), $seen, $counts)) {
                return;
            }

            $working?->close();
            $workingPath = null;

            if ($workingLocal !== null) {
                // Per version: a superseded run can only ever overwrite or delete its own copy.
                $workingPath = dirname($session->file_path).'/working-v'.$version.'.csv';
                $stream = fopen($workingLocal, 'rb');

                if ($stream !== false) {
                    Storage::disk($session->file_disk)->writeStream($workingPath, $stream);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }

            $duplicates = $counts['duplicates_in_file'] + $counts['matched'];
            $final = new ImportCounts(
                total: $counts['valid'] + $counts['invalid'] + $duplicates,
                valid: $counts['valid'],
                invalid: $counts['invalid'],
                duplicates: $duplicates,
                duplicatesInFile: $counts['duplicates_in_file'],
                matched: $counts['matched'],
                creates: $counts['creates'],
                updates: $counts['updates'],
                skips: $counts['skips'],
                templateRows: $counts['template_rows'],
                blankRows: $counts['blank_rows'],
            );

            $now = Clock::now();
            $updated = ImportModels::session()::query()
                ->whereKey($session->getKey())
                ->where('preview_version', $version)
                ->where('status', SessionStatus::Validating->value)
                ->update([
                    'status' => SessionStatus::Previewed->value,
                    'counts' => json_encode($final->toArray()),
                    'chunk_size' => $chunkSize,
                    'chunk_offsets' => json_encode($offsets),
                    'working_copy_path' => $workingPath,
                    'processed_rows' => $processed,
                    'progress' => 100,
                    'previewed_at' => Clock::format($now),
                    'last_progress_at' => Clock::format($now),
                    'updated_at' => Clock::format($now),
                ]);

            if ($updated === 0) {
                if ($workingPath !== null) {
                    Storage::disk($session->file_disk)->delete($workingPath);
                }

                return;
            }

            Event::dispatch(new ImportPreviewed(Audits::for($session->refresh(), null, Audits::actor($session))));
        } finally {
            $working?->close();
            $file?->close();
            TempFiles::remove($temp);
        }
    }

    /**
     * @param  array<int, list<Cell|null>>  $rows
     * @param  array<string, int>  $counts
     */
    private function window(ImportSession $session, int $version, RowPipeline $pipeline, array $rows, int $firstPosition, InFileKeys $seen, array &$counts): bool
    {
        $results = [];

        foreach ($pipeline->evaluate($rows, $seen, TemplateRows::window($rows, $firstPosition)) as $evaluation) {
            if ($evaluation->templateRow) {
                $counts['template_rows']++;

                continue;
            }

            $outcome = RowPipeline::outcome($evaluation->status, $session->mode);
            $counts[$outcome->value.'s'] = ($counts[$outcome->value.'s'] ?? 0) + ($evaluation->isValid() ? 1 : 0);

            match ($evaluation->status) {
                RowStatus::Valid => $counts['valid']++,
                RowStatus::Invalid, RowStatus::Failed => $counts['invalid']++,
                RowStatus::DuplicateInFile => $counts['duplicates_in_file']++,
                RowStatus::Matched => $counts['matched']++,
            };

            if ($evaluation->status === RowStatus::DuplicateInFile) {
                $counts['skips']++;
            }

            if ($evaluation->status !== RowStatus::Valid) {
                $results[] = self::result($session, $evaluation, $outcome->value);
            }
        }

        return $this->guarded($session, $version, static function () use ($results): void {
            RowResults::insert($results);
        });
    }

    /**
     * Run $write only while the session still validates this version, holding the session row
     * locked so a remap (which bumps the version) cannot slip in between the check and the write.
     *
     * @param  Closure(): void  $write
     */
    private function guarded(ImportSession $session, int $version, Closure $write): bool
    {
        return $session->getConnection()->transaction(static function () use ($session, $version, $write): bool {
            $current = ImportModels::session()::query()
                ->whereKey($session->getKey())
                ->where('preview_version', $version)
                ->where('status', SessionStatus::Validating->value)
                ->lockForUpdate()
                ->first([$session->getKeyName()]) !== null;

            if ($current) {
                $write();
            }

            return $current;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(ImportSession $session, RowEvaluation $evaluation, string $outcome): array
    {
        $errors = $evaluation->errors;

        if ($evaluation->status === RowStatus::DuplicateInFile) {
            $code = $evaluation->duplicateReason ?? 'duplicate_in_file';
            $errors = [CellError::row($code, Locales::line('imports::validation.'.$code, [], $session->locale))];
        }

        return [
            'session_id' => $session->getKey(),
            'row_number' => $evaluation->number,
            'status' => $evaluation->status->value,
            'outcome' => $evaluation->status === RowStatus::Invalid ? null : $outcome,
            'errors' => $errors === [] ? null : CellError::storedList($errors),
            'match_key' => $evaluation->matchKey,
            'match_candidate' => $evaluation->matchCandidate,
            'matched_record_id' => $evaluation->match?->getKey(),
        ];
    }

    private function progress(ImportSession $session, int $version, int $processed): bool
    {
        $total = max(1, $session->data_rows);
        $now = Clock::format(Clock::now());
        $updated = ImportModels::session()::query()
            ->whereKey($session->getKey())
            ->where('preview_version', $version)
            ->update(['processed_rows' => $processed, 'progress' => min(99, intdiv($processed * 100, $total)), 'last_progress_at' => $now, 'updated_at' => $now]);

        if ($updated === 0) {
            return false;
        }

        Event::dispatch(new ImportPreviewProgressed(Audits::for($session, null, Audits::actor($session)), $processed, $total));

        return true;
    }
}
