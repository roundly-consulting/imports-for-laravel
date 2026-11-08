<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Templates;

use Illuminate\Support\Str;
use RoundlyConsulting\Imports\Csv\CsvWriter;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Xlsx\CellReference;
use RoundlyConsulting\Imports\Xlsx\InputMessage;
use RoundlyConsulting\Imports\Xlsx\SheetLayout;
use RoundlyConsulting\Imports\Xlsx\XlsxWriter;
use Throwable;

/**
 * Builds an importer's template: headers in the requested locale (required ones marked ` *`), an
 * example row, and — CSV — a hint row, or — XLSX — a bold frozen header, column formats, dropdowns
 * for choice and yes/no columns and an input message per column.
 *
 * @internal
 */
final class TemplateBuilder
{
    public static function build(Importer $importer, TemplateFormat $format, string $locale): Template
    {
        $fields = TemplateRows::fields($importer);
        $directory = TempFiles::directory();
        $filename = Str::slug($importer->labelIn($locale)) ?: $importer->key();
        $path = $directory.DIRECTORY_SEPARATOR.'template.'.$format->value;

        try {
            $headers = array_map(static fn ($field): string => TemplateRows::header($field, $locale), $fields);
            $example = array_map(static fn ($field): string|int|float|bool|null => TemplateRows::example($field), $fields);
            $hint = array_map(static fn ($field): string => TemplateRows::hint($field, $locale), $fields);
            $rows = [$headers];

            if (ImportsConfig::hintRow($format)) {
                $rows[] = $hint;
            }

            if (ImportsConfig::templateExampleRow()) {
                $rows[] = $example;
            }

            if ($format === TemplateFormat::Csv) {
                $writer = new CsvWriter($path, ImportsConfig::csvTemplateDelimiter($locale), ImportsConfig::csvBom());

                foreach ($rows as $row) {
                    $writer->row($row);
                }

                $writer->close();

                return new Template($path, $directory, $format, $filename.'.csv');
            }

            $writer = new XlsxWriter($path, $directory);
            $lists = [];
            $listRows = [];
            $prompts = [];
            $formats = [];
            $widths = [];
            $validation = ImportsConfig::dataValidation();

            foreach ($fields as $index => $field) {
                $widths[$index] = min(60, max(10, mb_strlen($headers[$index]) + 4));
                $formats[$index] = match ($field->type) {
                    FieldType::Date => 'date',
                    FieldType::DateTime => 'datetime',
                    FieldType::Decimal => 'decimal:'.$field->scale,
                    FieldType::Integer, FieldType::Boolean => 'general',
                    default => 'text',
                };
                $prompts[$index] = new InputMessage($field->labelIn($locale), TemplateRows::hint($field, $locale));

                if (! $validation) {
                    continue;
                }

                $values = match ($field->type) {
                    FieldType::Choice => array_map(static fn (string $v): string => $field->optionLabel($v, $locale), array_map('strval', array_keys($field->options))),
                    FieldType::Boolean => [Locales::line('imports::templates.yes', [], $locale), Locales::line('imports::templates.no', [], $locale)],
                    default => null,
                };

                if ($values === null) {
                    continue;
                }

                $inline = '"'.implode(',', $values).'"';

                if (strlen($inline) <= 255 && ! array_filter($values, static fn (string $v): bool => str_contains($v, ',') || str_contains($v, '"'))) {
                    $lists[$index] = $inline;
                } else {
                    $column = count($listRows);
                    $listRows[$column] = $values;
                    $name = '_imports_'.$field->name;
                    $letters = CellReference::letters($column);
                    $writer->defineName($name, 'Lists!$'.$letters.'$1:$'.$letters.'$'.count($values));
                    $lists[$index] = $name;
                }
            }

            $writer->addSheet(Str::limit($importer->labelIn($locale), 28, ''), $rows, new SheetLayout(
                boldHeader: true,
                freezeHeader: true,
                widths: $widths,
                formats: array_filter($formats, static fn (string $f): bool => $f !== 'general'),
                lists: $lists,
                prompts: $prompts,
                validationRows: $importer->limits()->rows,
            ));

            if ($listRows !== []) {
                $writer->addSheet('Lists', self::transpose($listRows), new SheetLayout(hidden: true));
            }

            $writer->close();

            return new Template($path, $directory, $format, $filename.'.xlsx');
        } catch (Throwable $exception) {
            TempFiles::remove($directory);

            throw $exception;
        }
    }

    /**
     * @param  array<int, list<string>>  $columns
     * @return list<list<string|null>>
     */
    private static function transpose(array $columns): array
    {
        $height = max([0, ...array_map('count', $columns)]);
        $rows = [];

        for ($row = 0; $row < $height; $row++) {
            $cells = [];

            foreach ($columns as $values) {
                $cells[] = $values[$row] ?? null;
            }

            $rows[] = $cells;
        }

        return $rows;
    }
}
