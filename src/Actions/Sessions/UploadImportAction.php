<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Sessions;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\DataTransferObjects\UploadData;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportMapped;
use RoundlyConsulting\Imports\Events\ImportUploaded;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Mapping\HeaderNormalizer;
use RoundlyConsulting\Imports\Mapping\Suggester;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Reading\ReadOptions;
use RoundlyConsulting\Imports\Reading\SpreadsheetFile;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Clock;
use RoundlyConsulting\Imports\Support\Digests;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Keys;
use RoundlyConsulting\Imports\Support\KeyTypes;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\SavedMappingStore;
use RoundlyConsulting\Imports\Support\SessionFiles;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;
use Throwable;

/**
 * Upload a CSV or XLSX file for a row importer. Authorization comes first — before the file is
 * read, sniffed or stored. Then the file is checked whole (format by content, encoding, limits,
 * header row), stored on the import disk and a session created; a complete mapping suggestion
 * (or a saved mapping for this exact header row) is applied straight away.
 */
final readonly class UploadImportAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private SavedMappingStore $mappings,
    ) {}

    public function execute(UploadData $data): ImportSession
    {
        $importer = $this->registry->rows($data->importer);
        KeyTypes::assert($data->owner, 'owner');
        KeyTypes::assert($data->actor, 'actor');
        Access::check($importer, $data->actor, $data->owner, ImportOperation::Upload);

        $limits = $importer->limits();
        $declared = $data->source->declaredSize();

        if ($declared !== null && $declared > $limits->fileSizeBytes()) {
            throw ImportFileRejectedException::because(RejectionReason::TooLarge, [
                'limit' => number_format($limits->fileSizeKb, 0, '.', ' ').' KB',
                'actual' => number_format($declared / 1024, 0, '.', ' ').' KB',
            ]);
        }

        $locale = $data->locale ?? Locales::current();
        $timezone = ImportsConfig::timezone();
        $temp = TempFiles::directory();
        $file = null;
        $stored = null;

        try {
            $local = $data->source->localPath($temp);
            $file = SpreadsheetFile::open($local, ReadOptions::fromConfig($limits, $data->sheet, $data->delimiter, $data->headerRow, $timezone));

            if (! in_array($file->format(), $importer->formats(), true)) {
                throw ImportFileRejectedException::because(RejectionReason::FormatNotAccepted, ['format' => strtoupper($file->format()->value)]);
            }

            $headers = $file->headers();
            $dataRows = $file->count();
            $sha256 = Digests::file($local);
            $disk = ImportsConfig::disk();

            /** @var ImportSession $session */
            $session = ImportModels::session()::query()->create([
                'importer' => $importer->key(),
                'kind' => ImportKind::Rows,
                'owner_type' => $data->owner->getMorphClass(),
                'owner_id' => $data->owner->getKey(),
                'actor_type' => $data->actor?->getMorphClass(),
                'actor_id' => $data->actor?->getKey(),
                'status' => SessionStatus::Uploaded,
                'format' => $file->format(),
                'file_disk' => $disk,
                'file_path' => '',
                'file_name' => $data->source->originalName(),
                'file_size' => (int) filesize($local),
                'file_sha256' => $sha256,
                'encoding' => $file->encoding(),
                'delimiter' => $file->delimiter(),
                'delimiter_source' => $file->delimiterSource(),
                'sheet' => $file->sheet(),
                'sheets' => array_map(static fn ($sheet): array => ['name' => $sheet->name, 'index' => $sheet->index, 'visible' => $sheet->visible], $file->sheets()),
                'header_row' => $file->headerRow(),
                'headers' => $headers,
                'mode' => $importer->defaultMode(),
                'locale' => $locale,
                'timezone' => $timezone,
                'data_rows' => $dataRows,
                'counts' => [],
                'expires_at' => Clock::now()->add(ImportsConfig::expireAfter()),
            ]);

            $stored = SessionFiles::directory(Digests::owner($data->owner), Keys::of($session)).'/source.'.$file->format()->value;
            $stream = fopen($local, 'rb');

            if ($stream === false || ! Storage::disk($disk)->writeStream($stored, $stream)) {
                throw ImportFileRejectedException::because(RejectionReason::Unreadable);
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            $saved = ImportsConfig::mappingsEnabled() && ! HeaderNormalizer::collide($headers)
                ? $this->mappings->bySignature($data->owner, $importer->key(), HeaderNormalizer::signature($headers))
                : null;

            $session->file_path = $stored;
            SessionState::applySuggestion($session, Suggester::suggest($importer, $headers, $file->displayHeaders(), $locale, $saved));
            $session->save();
        } catch (Throwable $exception) {
            if ($stored !== null) {
                Storage::disk(ImportsConfig::disk())->deleteDirectory(dirname($stored));
            }

            if (isset($session)) {
                $session->forceDelete();
            }

            throw $exception;
        } finally {
            $file?->close();
            TempFiles::remove($temp);
        }

        $session->setRelation('owner', $data->owner);
        $session->setRelation('actor', $data->actor);

        Event::dispatch(new ImportUploaded(Audits::for($session, null, $data->actor)));

        if ($session->status === SessionStatus::Mapped) {
            Event::dispatch(new ImportMapped(Audits::for($session, null, $data->actor)));
        }

        return $session;
    }
}
