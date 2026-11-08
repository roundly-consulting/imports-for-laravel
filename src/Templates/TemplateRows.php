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

    /**
     * Whether a data row is the template's hint row (in any shipped locale) or its unchanged
     * example row.
     *
     * @param  array<string, Cell|null>  $cells  field => cell, the mapped fields only
     */
    public static function isTemplateRow(Importer $importer, array $cells): bool
    {
        if ($cells === [] || array_filter($cells) === []) {
            return false;
        }

        $fields = [];

        foreach ($importer->fields() as $field) {
            $fields[$field->name] = $field;
        }

        $candidates = [static fn (Field $field): string => self::canonical(self::example($field))];

        foreach (Locales::matching() as $locale) {
            $candidates[] = static fn (Field $field): string => self::canonical(self::hint($field, $locale));
        }

        foreach ($candidates as $expected) {
            $matches = true;

            foreach ($cells as $name => $cell) {
                if (! isset($fields[$name]) || self::canonical($cell?->display()) !== $expected($fields[$name])) {
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
