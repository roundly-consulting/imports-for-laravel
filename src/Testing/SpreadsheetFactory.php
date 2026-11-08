<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Testing;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Xlsx\XlsxWriter;
use RuntimeException;
use ZipArchive;

/**
 * Builds spreadsheet uploads for tests — in this package and in host apps:
 *
 * ```php
 * $file = SpreadsheetFactory::xlsx(['Asset tag', 'Name'], [['AST-1', 'Laptop']], sheet: 'Assets');
 * $this->post('/assets/import', ['file' => $file]);
 * ```
 */
final class SpreadsheetFactory
{
    /** Windows-1250 bytes for the Central European letters (mbstring has no CP1250). */
    private const array CP1250 = [
        'Š' => "\x8A", 'Ť' => "\x8D", 'Ž' => "\x8E", 'š' => "\x9A", 'ť' => "\x9D", 'ž' => "\x9E",
        'Ľ' => "\xBC", 'ľ' => "\xBE", 'Č' => "\xC8", 'č' => "\xE8", 'Á' => "\xC1", 'á' => "\xE1",
        'É' => "\xC9", 'é' => "\xE9", 'Í' => "\xCD", 'í' => "\xED", 'Ó' => "\xD3", 'ó' => "\xF3",
        'Ú' => "\xDA", 'ú' => "\xFA", 'Ý' => "\xDD", 'ý' => "\xFD", 'ô' => "\xF4", 'Ô' => "\xD4",
        'ä' => "\xE4", 'Ä' => "\xC4", 'ň' => "\xF2", 'Ň' => "\xD2", 'ď' => "\xEF", 'Ď' => "\xCF",
        'ŕ' => "\xE0", 'Ŕ' => "\xC0", 'ĺ' => "\xE5", 'Ĺ' => "\xC5",
    ];

    /**
     * @param  list<string|null>  $header  an empty list writes no header row
     * @param  list<list<string|int|float|bool|null>>  $rows
     */
    public static function csv(
        array $header,
        array $rows,
        string $delimiter = ',',
        bool $bom = false,
        string $encoding = 'UTF-8',
        string $eol = "\n",
        string $name = 'import.csv',
    ): UploadedFile {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Cannot build the CSV.');
        }

        foreach ($header === [] ? $rows : [$header, ...$rows] as $row) {
            fputcsv($stream, array_map(static fn (string|int|float|bool|null $v): string => is_bool($v) ? ($v ? 'TRUE' : 'FALSE') : (string) $v, $row), $delimiter, '"', '', $eol);
        }

        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        $content = match (strtoupper($encoding)) {
            'UTF-8' => $content,
            'WINDOWS-1250', 'CP1250' => strtr($content, self::CP1250),
            default => mb_convert_encoding($content, $encoding, 'UTF-8'),
        };

        if ($bom) {
            $content = match (strtoupper($encoding)) {
                'UTF-16LE' => "\xFF\xFE".$content,
                'UTF-16BE', 'UTF-16' => "\xFE\xFF".$content,
                default => "\xEF\xBB\xBF".$content,
            };
        }

        return self::upload($name, $content, 'text/csv');
    }

    /**
     * @param  list<string|null>  $header  an empty list writes no header row
     * @param  list<list<string|int|float|bool|DateTimeInterface|null>>  $rows
     * @param  array<string, list<list<string|int|float|bool|DateTimeInterface|null>>>  $extraSheets  name => rows (header included)
     */
    public static function xlsx(
        array $header,
        array $rows,
        string $sheet = 'Sheet1',
        array $extraSheets = [],
        bool $date1904 = false,
        bool $sharedStrings = true,
        string $name = 'import.xlsx',
    ): UploadedFile {
        $directory = TempFiles::directory();

        try {
            $path = $directory.DIRECTORY_SEPARATOR.'book.xlsx';
            $writer = new XlsxWriter($path, $directory, $sharedStrings, $date1904);
            $writer->addSheet($sheet, $header === [] ? $rows : [$header, ...$rows]);

            foreach ($extraSheets as $sheetName => $sheetRows) {
                $writer->addSheet($sheetName, $sheetRows);
            }

            $writer->close();

            return self::upload($name, (string) file_get_contents($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } finally {
            TempFiles::remove($directory);
        }
    }

    /**
     * @param  array<string, string>  $entries  entry name => content
     */
    public static function zip(array $entries, string $name = 'files.zip'): UploadedFile
    {
        $path = self::path('zip');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot build the ZIP.');
        }

        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }

        $zip->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    /**
     * A plain file upload.
     */
    public static function file(string $name, string $content, string $mime = 'application/octet-stream'): UploadedFile
    {
        return self::upload($name, $content, $mime);
    }

    private static function upload(string $name, string $content, string $mime): UploadedFile
    {
        $path = self::path(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private static function path(string $extension): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'roundly-imports-factory';

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        return $directory.DIRECTORY_SEPARATOR.Str::ulid().'.'.$extension;
    }
}
