<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Preview;

use Generator;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Csv\CsvWriter;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\SessionRows;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Templates\Template;
use RoundlyConsulting\Imports\Xlsx\SheetLayout;
use RoundlyConsulting\Imports\Xlsx\XlsxWriter;
use Throwable;

/**
 * The failing rows of a preview (or commit) as a file to fix and re-upload: the original header
 * row plus an "Errors" column, each failing row as it was uploaded with its messages. A file batch
 * gets one row per refused file (its name and messages). The report's own labels — the "Errors"
 * column, the sheet, unnamed columns — follow `$locale` (default: the session's); the messages
 * were stored in the session's locale. CSV output neutralises formula-like cells — it echoes
 * values other people uploaded.
 */
final readonly class BuildErrorReportAction
{
    public function __construct(private ImporterRegistry $registry) {}

    public function execute(ImportSession $session, TemplateFormat $format, ?Model $actor, ?string $locale = null): Template
    {
        $definition = $this->registry->find($session->importer);
        Access::check($definition, $actor, Audits::owner($session), ImportOperation::View);
        $locale ??= $session->locale;

        $messages = [];
        $labels = [];

        foreach (ImportModels::rowResult()::query()
            ->where('session_id', $session->getKey())
            ->whereIn('status', [RowStatus::Invalid->value, RowStatus::Failed->value])
            ->orderBy('row_number')
            ->cursor() as $result) {
            $messages[$result->row_number] = implode('; ', array_map(
                static fn (array $error): string => $error['message'],
                $result->errors ?? [],
            ));
            $labels[$result->row_number] = (string) $result->label;
        }

        $errors = Locales::line('imports::templates.errors', [], $locale);
        $directory = TempFiles::directory();
        $path = $directory.DIRECTORY_SEPARATOR.'errors.'.$format->value;
        $rows = $definition instanceof FileImporter
            ? self::files($messages, $labels, Locales::line('imports::templates.file', [], $locale), $errors)
            : self::rows($session, $messages, $directory, [...SessionState::displayHeaders($session, $locale), $errors]);

        try {
            if ($format === TemplateFormat::Csv) {
                $writer = new CsvWriter($path, $session->delimiter ?? ImportsConfig::csvTemplateDelimiter($locale), ImportsConfig::csvBom());

                foreach ($rows as $row) {
                    $writer->row($row);
                }

                $writer->close();
            } else {
                $writer = new XlsxWriter($path, $directory);
                $writer->addSheet($errors, $rows, new SheetLayout(boldHeader: true, freezeHeader: true));
                $writer->close();
            }
        } catch (Throwable $exception) {
            TempFiles::remove($directory);

            throw $exception;
        }

        $name = pathinfo($session->file_name, PATHINFO_FILENAME);

        return new Template($path, $directory, $format, ($name === '' ? 'import' : $name).'-errors.'.$format->value);
    }

    /**
     * A file batch: one row per refused file — its name and messages.
     *
     * @param  array<int, string>  $messages
     * @param  array<int, string>  $labels
     * @return Generator<int, list<string|null>>
     */
    private static function files(array $messages, array $labels, string $file, string $errors): Generator
    {
        yield [$file, $errors];

        foreach ($messages as $number => $message) {
            yield [$labels[$number] ?? '', $message];
        }
    }

    /**
     * A row import: each failing row as it was uploaded, plus its messages.
     *
     * @param  array<int, string>  $messages
     * @param  list<string>  $headers
     * @return Generator<int, list<string|null>>
     */
    private static function rows(ImportSession $session, array $messages, string $directory, array $headers): Generator
    {
        $width = count($session->headers);

        yield $headers;

        foreach (SessionRows::pick($session, array_fill_keys(array_keys($messages), true), $directory, ImportsConfig::MAX_COLUMNS_CEILING) as $number => $cells) {
            $values = [];

            for ($i = 0; $i < $width; $i++) {
                $values[] = ($cells[$i] ?? null)?->display();
            }

            $values[] = $messages[$number] ?? '';

            yield $values;
        }
    }
}
