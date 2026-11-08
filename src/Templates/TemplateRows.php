<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Templates;

use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\Numbers;

/**
 * A template's hint and example rows — written by the template builder and recognised on import,
 * so a filled-in template whose hint or unchanged example row was left in never imports them.
 *
 * @internal
 */
final class TemplateRows
{
    /**
     * @return list<Field>
     */
    public static function fields(Importer $importer): array
    {
        return array_values(array_filter($importer->fields(), static fn (Field $field): bool => $field->inTemplate));
    }

    public static function header(Field $field, string $locale): string
    {
        return $field->labelIn($locale).($field->required ? ' *' : '');
    }

    public static function hint(Field $field, string $locale): string
    {
        $parts = [Locales::line($field->required ? 'imports::templates.required' : 'imports::templates.optional', [], $locale)];
        $example = $field->exampleValue();
        $example = is_scalar($example) ? (string) $example : '';

        $parts[] = match ($field->type) {
            FieldType::Choice => Locales::line('imports::templates.one_of', ['values' => implode(', ', array_map(
                static fn (string $value): string => $field->optionLabel($value, $locale),
                array_map('strval', array_keys($field->options)),
            ))], $locale),
            FieldType::Date => Locales::line('imports::templates.date', ['example' => $example], $locale),
            FieldType::DateTime => Locales::line('imports::templates.datetime', ['example' => $example], $locale),
            FieldType::Boolean => Locales::line('imports::templates.boolean', [], $locale),
            FieldType::Integer => Locales::line('imports::templates.integer', [], $locale),
            FieldType::Decimal => Locales::line('imports::templates.decimal', ['example' => $example], $locale),
            FieldType::Email => Locales::line('imports::templates.email', [], $locale),
            FieldType::Reference => Locales::line('imports::templates.reference', [
                'field' => $field->labelIn($locale),
                'columns' => implode(' '.Locales::line('imports::templates.or', [], $locale).' ', $field->lookup),
            ], $locale),
            FieldType::Text => '',
        };

        if (($help = $field->helpIn($locale)) !== null) {
            $parts[] = $help;
        }

        return implode(' · ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    public static function example(Field $field): string|int|float|bool|null
    {
        $value = $field->exampleValue();

        return is_scalar($value) || $value === null ? $value : null;
    }

    /** A template puts at most this many rows (hint, example) under its header. */
    public const int WINDOW = 2;

    /**
     * What a hint row (in any shipped locale) or an unchanged example row holds, per field — built
     * once per pipeline, not per row.
     *
     * @param  array<string, Field>  $fields  field name => field
     * @return list<array<string, string>> field name => canonical value, one map per candidate row
     */
    public static function expectations(array $fields): array
    {
        $expectations = [array_map(static fn (Field $field): string => self::canonical(self::example($field)), $fields)];

        foreach (Locales::matching() as $locale) {
            $expectations[] = array_map(static fn (Field $field): string => self::canonical(self::hint($field, $locale)), $fields);
        }

        return $expectations;
    }

    /**
     * Whether a data row is one of the template's own rows. Only the first {@see self::WINDOW}
     * non-blank data rows can be: a real row further down that happens to equal the examples
     * imports.
     *
     * @param  list<array<string, string>>  $expectations
     * @param  array<string, Cell|null>  $cells  field => cell, the mapped fields only
     */
    public static function matches(array $expectations, array $cells): bool
    {
        if ($cells === [] || array_filter($cells) === []) {
            return false;
        }

        foreach ($expectations as $expected) {
            $matches = true;

            foreach ($cells as $name => $cell) {
                if (! isset($expected[$name]) || self::canonical($cell?->display()) !== $expected[$name]) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row numbers of a run of rows (in file order, blank rows already skipped) that fall in the
     * template window, given the position of the run's first row among the file's data rows.
     *
     * @param  array<int, mixed>  $rows  row number => cells
     * @return list<int>
     */
    public static function window(array $rows, int $firstPosition): array
    {
        return $firstPosition >= self::WINDOW ? [] : array_slice(array_keys($rows), 0, self::WINDOW - $firstPosition);
    }

    private static function canonical(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = mb_strtolower(trim((string) $value));

        return is_numeric($value) ? (Numbers::plain($value) ?? $value) : $value;
    }
}
