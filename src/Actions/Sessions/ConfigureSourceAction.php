<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Sessions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\Enums\DelimiterSource;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Enums\SourceFormat;
use RoundlyConsulting\Imports\Events\ImportMapped;
use RoundlyConsulting\Imports\Mapping\HeaderNormalizer;
use RoundlyConsulting\Imports\Mapping\Suggester;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Reading\ReadOptions;
use RoundlyConsulting\Imports\Reading\SpreadsheetFile;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\SavedMappingStore;
use RoundlyConsulting\Imports\Support\SessionFiles;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;

/**
 * Change how the stored file is read — the sheet (XLSX), the delimiter (CSV) or the header row —
 * and re-read its headers. The mapping is suggested again and any preview is dropped.
 */
final readonly class ConfigureSourceAction
{
    public function __construct(
        private ImporterRegistry $registry,
        private SavedMappingStore $mappings,
    ) {}

    public function execute(ImportSession $session, ?Model $actor, string|int|null $sheet = null, ?string $delimiter = null, ?int $headerRow = null): ImportSession
    {
        $session->refresh();
        $importer = $this->registry->rows($session->importer);
        $owner = Audits::owner($session);
        Access::check($importer, $actor, $owner, ImportOperation::Upload);
        SessionState::assertBeforeCommit($session);

        $temp = TempFiles::directory();
        $file = null;

        try {
            $local = SessionFiles::localSource($session, $temp);
            $file = SpreadsheetFile::open($local, ReadOptions::fromConfig(
                $importer->limits(),
                $session->format === SourceFormat::Xlsx ? ($sheet ?? $session->sheet) : null,
                $session->format === SourceFormat::Csv ? ($delimiter ?? ($session->delimiter_source === DelimiterSource::Sniffed ? null : $session->delimiter)) : null,
                $headerRow ?? $session->header_row,
                $session->timezone,
            ));

            $headers = $file->headers();
            $dataRows = $file->count();

            SessionState::resetPreview($session);
            $session->forceFill([
                'sheet' => $file->sheet(),
                'delimiter' => $file->delimiter(),
                'delimiter_source' => $delimiter !== null ? DelimiterSource::Override : $file->delimiterSource(),
                'header_row' => $file->headerRow(),
                'headers' => $headers,
                'data_rows' => $dataRows,
            ]);

            $saved = ImportsConfig::mappingsEnabled() && ! HeaderNormalizer::collide($headers)
                ? $this->mappings->bySignature($owner, $importer->key(), HeaderNormalizer::signature($headers))
                : null;

            SessionState::applySuggestion($session, Suggester::suggest($importer, $headers, $file->displayHeaders(), $session->locale, $saved));
            $session->save();
        } finally {
            $file?->close();
            TempFiles::remove($temp);
        }

        if ($session->status === SessionStatus::Mapped) {
            Event::dispatch(new ImportMapped(Audits::for($session, null, $actor)));
        }

        return $session;
    }
}
