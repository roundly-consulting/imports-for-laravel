<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Preview;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\DataTransferObjects\ImportCounts;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportPreviewed;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\FileDuplicateCheck;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\Locales;

/**
 * The preview of a file batch: per file valid, invalid (extension, size, empty) or a duplicate —
 * by SHA-256 within the batch or against this owner's earlier imports that were not undone.
 *
 * @internal
 */
final readonly class PreviewFilesAction
{
    public function execute(ImportSession $session, FileImporter $importer): void
    {
        $owner = Audits::owner($session);
        $results = ImportModels::rowResult()::query()->where('session_id', $session->getKey())->orderBy('row_number')->get();
        $hashes = array_values(array_filter($results->where('status', '!=', RowStatus::Invalid)->pluck('content_hash')->all(), 'is_string'));
        $earlier = FileDuplicateCheck::earlier($importer, $owner, $hashes, Keys::of($session));
        $seen = [];
        $counts = ['valid' => 0, 'invalid' => 0, 'in_file' => 0, 'matched' => 0];

        foreach ($results as $result) {
            if ($result->status === RowStatus::Invalid) {
                $counts['invalid']++;

                continue;
            }

            $hash = (string) $result->content_hash;
            [$status, $code] = match (true) {
                isset($seen[$hash]) => [RowStatus::DuplicateInFile, 'duplicate_file'],
                isset($earlier[$hash]) => [RowStatus::Matched, 'file_imported_earlier'],
                default => [RowStatus::Valid, null],
            };
            $seen[$hash] = true;

            $result->forceFill([
                'status' => $status,
                'outcome' => $status === RowStatus::Valid ? RowOutcome::Create : RowOutcome::Skip,
                'errors' => $code === null ? null : [['field' => null, 'column' => null, 'code' => $code, 'message' => Locales::line('imports::validation.'.$code, [], $session->locale), 'value' => $result->label]],
                'matched_record_id' => null,
            ])->save();

            $counts[match ($status) {
                RowStatus::DuplicateInFile => 'in_file',
                RowStatus::Matched => 'matched',
                default => 'valid',
            }]++;
        }

        $size = max(ImportsConfig::chunkSize(), (int) ceil(max(1, $results->count()) / ImportsConfig::maxChunks()));
        $offsets = [];

        foreach ($results->values() as $position => $result) {
            if ($position % $size === 0) {
                $offsets[] = [0, $result->row_number];
            }
        }

        $duplicates = $counts['in_file'] + $counts['matched'];
        $final = new ImportCounts(
            total: $counts['valid'] + $counts['invalid'] + $duplicates,
            valid: $counts['valid'],
            invalid: $counts['invalid'],
            duplicates: $duplicates,
            duplicatesInFile: $counts['in_file'],
            matched: $counts['matched'],
            creates: $counts['valid'],
            skips: $duplicates,
        );

        $session->forceFill([
            'status' => SessionStatus::Previewed,
            'counts' => $final->toArray(),
            'chunk_size' => $size,
            'chunk_offsets' => $offsets,
            'processed_rows' => $results->count(),
            'progress' => 100,
            'previewed_at' => Clock::now(),
            'last_progress_at' => Clock::now(),
        ])->save();

        Event::dispatch(new ImportPreviewed(Audits::for($session, null, Audits::actor($session))));
    }
}
