<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Sessions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Imports\DataTransferObjects\UploadFilesData;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportUploaded;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Models\ImportSession;
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
use RoundlyConsulting\Imports\Support\RowResults;
use RoundlyConsulting\Imports\Support\SessionFiles;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Zip\ByteBudget;
use RoundlyConsulting\Imports\Zip\EntryName;
use RoundlyConsulting\Imports\Zip\ZipGuard;
use Throwable;

/**
 * Upload a file batch: many files and/or ZIP archives, each file one future record. Archives are
 * checked whole before anything is extracted (count, sizes, ratio, names, symlinks, encryption)
 * and every entry is streamed under a generated name (`entries/<n>`) with the real inflated bytes
 * counted — no path from an archive ever touches the disk. Any violation rejects the whole upload
 * and deletes what was staged.
 */
final readonly class UploadFilesAction
{
    public function __construct(private ImporterRegistry $registry) {}

    public function execute(UploadFilesData $data): ImportSession
    {
        $importer = $this->registry->files($data->importer);
        KeyTypes::assert($data->owner, 'owner');
        KeyTypes::assert($data->actor, 'actor');
        Access::check($importer, $data->actor, $data->owner, ImportOperation::Upload);

        $disk = ImportsConfig::disk();
        $temp = TempFiles::directory();
        $session = null;

        try {
            /** @var ImportSession $session */
            $session = ImportModels::session()::query()->create([
                'importer' => $importer->key(),
                'kind' => ImportKind::Files,
                'owner_type' => $data->owner->getMorphClass(),
                'owner_id' => $data->owner->getKey(),
                'actor_type' => $data->actor?->getMorphClass(),
                'actor_id' => $data->actor?->getKey(),
                'status' => SessionStatus::Uploaded,
                'file_disk' => $disk,
                'file_path' => '',
                'file_name' => count($data->files) === 1 ? self::name($data->files[0]) : count($data->files).' files',
                'header_row' => 0,
                'headers' => [],
                'mode' => DuplicateMode::Skip,
                'locale' => $data->locale ?? Locales::current(),
                'timezone' => ImportsConfig::timezone(),
                'counts' => [],
                'expires_at' => Clock::now()->add(ImportsConfig::expireAfter()),
            ]);

            $directory = SessionFiles::directory(Digests::owner($data->owner), Keys::of($session));
            $rows = [];
            $index = 0;
            $total = 0;

            foreach ($data->files as $file) {
                $path = $file->getRealPath();

                if ($path === false) {
                    throw ImportFileRejectedException::because(RejectionReason::Unreadable);
                }

                if (self::isArchive($file, $path)) {
                    if ((int) filesize($path) > ImportsConfig::maxArchiveSizeKb() * 1024) {
                        throw ImportFileRejectedException::because(RejectionReason::ArchiveTooLarge, ['limit' => ImportsConfig::maxArchiveSizeKb().' KB']);
                    }

                    $zip = ZipGuard::open($path, RejectionReason::ZipInconsistent);

                    try {
                        $entries = ZipGuard::inspectArchive(
                            $zip,
                            ImportsConfig::maxFiles(),
                            ImportsConfig::maxUncompressed(),
                            max($importer->accepts()->maxBytes(), 1),
                            ImportsConfig::maxRatio(),
                            ImportsConfig::ratioMinSize(),
                            ImportsConfig::filesIgnore(),
                        );

                        $budget = new ByteBudget($importer->accepts()->maxBytes(), ImportsConfig::maxUncompressed() - $total, ImportsConfig::maxRatio(), ImportsConfig::ratioMinSize());

                        foreach ($entries as $entry) {
                            $index++;
                            self::guardCount($index);
                            $staged = $temp.DIRECTORY_SEPARATOR.'entry';
                            ZipGuard::extract($zip, $entry, $staged, $budget);
                            $rows[] = self::stage($session, $importer, $disk, $directory, $index, EntryName::basename($entry->name), $staged);
                            @unlink($staged);
                        }

                        $total += $budget->used;
                    } finally {
                        @$zip->close();
                    }

                    continue;
                }

                $index++;
                self::guardCount($index);
                $rows[] = self::stage($session, $importer, $disk, $directory, $index, self::name($file), $path);
            }

            RowResults::insert($rows);

            $session->forceFill([
                'file_path' => $directory.'/entries',
                'file_size' => array_sum(array_map(static fn (array $row): int => is_int($row['file_size'] ?? null) ? $row['file_size'] : 0, $rows)),
                'data_rows' => count($rows),
                'status' => SessionStatus::Mapped,
            ])->save();
        } catch (Throwable $exception) {
            if ($session !== null) {
                Storage::disk($disk)->deleteDirectory(SessionFiles::directory(Digests::owner($data->owner), Keys::of($session)));
                ImportModels::rowResult()::query()->where('session_id', $session->getKey())->delete();
                $session->forceDelete();
            }

            throw $exception;
        } finally {
            TempFiles::remove($temp);
        }

        $session->setRelation('owner', $data->owner);
        Event::dispatch(new ImportUploaded(Audits::for($session, null, $data->actor)));

        return $session;
    }

    private static function guardCount(int $index): void
    {
        if ($index > ImportsConfig::maxFiles()) {
            throw ImportFileRejectedException::because(RejectionReason::TooManyFiles, ['limit' => ImportsConfig::maxFiles(), 'actual' => $index]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function stage(ImportSession $session, FileImporter $importer, string $disk, string $directory, int $index, string $name, string $path): array
    {
        $size = (int) filesize($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $rules = $importer->accepts();
        $errors = [];

        $code = match (true) {
            $size === 0 => 'empty_file',
            ! $rules->allows($extension) => 'extension_not_allowed',
            $size > $rules->maxBytes() => 'file_too_large',
            default => null,
        };

        if ($code !== null) {
            $errors[] = ['field' => null, 'column' => null, 'code' => $code, 'message' => Locales::line('imports::validation.'.$code, [
                'extension' => $extension === '' ? '—' : $extension,
                'allowed' => implode(', ', $rules->extensions),
                'max' => intdiv($rules->maxBytes(), 1024).' KB',
            ], $session->locale), 'value' => mb_substr($name, 0, 100)];
        }

        $stored = $directory.'/entries/'.$index;
        $stream = fopen($path, 'rb');

        if ($stream === false || ! Storage::disk($disk)->writeStream($stored, $stream)) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        return [
            'session_id' => $session->getKey(),
            'row_number' => $index,
            'status' => ($errors === [] ? RowStatus::Valid : RowStatus::Invalid)->value,
            'outcome' => $errors === [] ? 'create' : null,
            'errors' => $errors === [] ? null : $errors,
            'label' => mb_substr($name, 0, 255),
            'file_size' => $size,
            'content_hash' => $size === 0 ? null : Digests::file($path),
            'stored_path' => $stored,
        ];
    }

    private static function name(UploadedFile $file): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '_', mb_scrub($file->getClientOriginalName(), 'UTF-8')));

        return mb_substr($name === '' ? 'file' : $name, 0, 255);
    }

    private static function isArchive(UploadedFile $file, string $path): bool
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            return false;
        }

        $handle = @fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);

        if ($handle !== false) {
            fclose($handle);
        }

        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06";
    }
}
