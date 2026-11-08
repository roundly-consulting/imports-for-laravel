<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Enums\CellType;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\Numbers;
use RoundlyConsulting\Imports\Xlsx\SerialDate;
use Throwable;

/**
 * The per-cell value pipeline, in its normative order: trim (NBSP, U+202F, zero-width too) →
 * blank is null → the field's `normalizeUsing()` → type coercion → `default()`. A failure is a
 * cell error, never an exception.
 *
 * @internal
 */
final class CellNormalizer
{
    /** @var array<string, BooleanWords> */
    private static array $booleans = [];

    /**
     * @param  list<string>  $dateFormats
     * @param  list<string>  $datetimeFormats
     */
    public function __construct(
        private readonly string $locale,
        private readonly string $timezone,
        private readonly int $cellLength,
        private readonly array $dateFormats,
        private readonly array $datetimeFormats,
        private readonly bool $date1904 = false,
    ) {}

    /**
     * @param  bool  $date1904  the workbook's date system: a plain number mapped to a date field is
     *                          a serial of that system
     */
    public static function forSession(string $locale, string $timezone, bool $date1904 = false): self
    {
        return new self($locale, $timezone, ImportsConfig::cellLength(), ImportsConfig::dateFormats(), ImportsConfig::datetimeFormats(), $date1904);
    }

    public function normalize(Field $field, ?Cell $cell): Coerced
    {
        if ($cell !== null && $cell->type === CellType::Error) {
            return Coerced::error('spreadsheet_error', ['code' => $cell->display()]);
        }

        $value = $cell?->value;

        if (is_string($value)) {
            $value = self::trim($value);

            if ($value === '') {
                $value = null;
            } elseif (mb_strlen($value) > $this->cellLength) {
                return Coerced::error('too_long', ['max' => $this->cellLength]);
            }
        }

        $isNumber = $cell?->type === CellType::Number;

        if ($field->normalizer !== null) {
            $value = ($field->normalizer)($value, $cell);
            $isNumber = $isNumber && is_string($value);

            if (is_string($value)) {
                $value = self::trim($value);
                $value = $value === '' ? null : $value;
            }
        }

        if ($value === null) {
            return Coerced::value($field->hasDefault ? $field->default : null);
        }

        try {
            return $this->coerce($field, $value, $isNumber);
        } catch (Throwable) {
            return Coerced::error('invalid_value');
        }
    }

    public static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{202F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+|[\s\x{00A0}\x{202F}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+$/u', '', $value);
    }

    private function coerce(Field $field, mixed $value, bool $isNumber): Coerced
    {
        return match ($field->type) {
            FieldType::Text, FieldType::Reference => $this->text($value, $isNumber),
            FieldType::Integer => $this->integer($value, $isNumber),
            FieldType::Decimal => $this->decimal($field, $value, $isNumber),
            FieldType::Boolean => $this->boolean($field, $value),
            FieldType::Date => $this->date($field, $value, false),
            FieldType::DateTime => $this->date($field, $value, true),
            FieldType::Email => is_string($value) || is_int($value) ? Coerced::value(mb_strtolower((string) $value)) : Coerced::error('invalid_value'),
            FieldType::Choice => $this->choice($field, $value),
        };
    }

    private function text(mixed $value, bool $isNumber): Coerced
    {
        return match (true) {
            $value instanceof DateTimeInterface => Coerced::value($value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s')),
            is_bool($value) => Coerced::value($value ? 'TRUE' : 'FALSE'),
            is_int($value), is_float($value) => Coerced::value(Numbers::plain((string) $value) ?? (string) $value),
            is_string($value) && $isNumber => Coerced::value(Numbers::plain($value) ?? $value),
            is_string($value) => Coerced::value($value),
            default => Coerced::error('invalid_value'),
        };
    }

    private function integer(mixed $value, bool $isNumber): Coerced
    {
        $plain = $this->numeric($value, $isNumber);

        if ($plain === null || ! Numbers::isInteger($plain)) {
            return Coerced::error('invalid_integer');
        }

        return Numbers::fitsInt($plain) ? Coerced::value((int) $plain) : Coerced::error('integer_overflow');
    }

    private function decimal(Field $field, mixed $value, bool $isNumber): Coerced
    {
        $plain = $this->numeric($value, $isNumber);

        return $plain === null ? Coerced::error('invalid_number') : Coerced::value(Numbers::round($plain, $field->scale));
    }

    private function numeric(mixed $value, bool $isNumber): ?string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_float($value) => Numbers::plain(sprintf('%.15G', $value)),
            is_string($value) && $isNumber => Numbers::plain($value),
            is_string($value) => Numbers::parse($value, $this->locale),
            default => null,
        };
    }

    private function boolean(Field $field, mixed $value): Coerced
    {
        if (is_bool($value)) {
            return Coerced::value($value);
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return Coerced::error('invalid_boolean');
        }

        $word = mb_strtolower($value);
        $words = self::booleanWords();

        if (in_array($word, [...$words->true, ...array_map(mb_strtolower(...), $field->trueValues)], true)) {
            return Coerced::value(true);
        }

        if (in_array($word, [...$words->false, ...array_map(mb_strtolower(...), $field->falseValues)], true)) {
            return Coerced::value(false);
        }

        return Coerced::error('invalid_boolean');
    }

    private function date(Field $field, mixed $value, bool $withTime): Coerced
    {
        if ($value instanceof DateTimeInterface) {
            $date = CarbonImmutable::instance($value);

            return Coerced::value($withTime ? $date : $date->startOfDay());
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1)) {
            $serial = (float) $value;

            if ($serial < 1 || $serial > SerialDate::MAX) {
                return Coerced::error($withTime ? 'invalid_datetime' : 'invalid_date');
            }

            $date = SerialDate::toDate((string) $value, $this->date1904, $this->timezone);

            return $date === null
                ? Coerced::error($withTime ? 'invalid_datetime' : 'invalid_date')
                : Coerced::value($withTime ? $date : $date->startOfDay());
        }

        if (! is_string($value)) {
            return Coerced::error('invalid_date');
        }

        $formats = $withTime
            ? [...$this->datetimeFormats, ...$this->dateFormats, ...$field->formats]
            : [...$this->dateFormats, ...$field->formats];

        foreach ($formats as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value, $this->timezone);
            } catch (Throwable) {
                continue;     // Carbon throws where PHP returns false: not this format
            }

            // A round trip refuses what PHP would silently roll over (31.02.2026 → 03.03.2026).
            if ($date instanceof CarbonImmutable && $date->format($format) === $value) {
                return Coerced::value($withTime ? $date : $date->startOfDay());
            }
        }

        return Coerced::error($withTime ? 'invalid_datetime' : 'invalid_date', ['format' => $withTime ? 'yyyy-mm-dd hh:mm' : 'yyyy-mm-dd']);
    }

    private function choice(Field $field, mixed $value): Coerced
    {
        if (is_bool($value) || $value instanceof DateTimeInterface || ! is_scalar($value)) {
            return Coerced::error('invalid_choice', ['values' => implode(', ', array_keys($field->options))]);
        }

        // The value as typed first (`01`, `1.50` are codes), then as a number (`1.0` from a number
        // cell) when that matched nothing.
        $raw = mb_strtolower((string) $value);
        $plain = Numbers::plain((string) $value);

        foreach (array_unique([$raw, $plain === null ? $raw : mb_strtolower($plain)]) as $needle) {
            foreach (array_keys($field->options) as $option) {
                $option = (string) $option;

                if (mb_strtolower($option) === $needle) {
                    return Coerced::value($option);
                }

                foreach (Locales::matching($this->locale) as $locale) {
                    if (mb_strtolower($field->optionLabel($option, $locale)) === $needle) {
                        return Coerced::value($option);
                    }
                }
            }
        }

        return Coerced::error('invalid_choice', ['values' => implode(', ', array_map('strval', array_keys($field->options)))]);
    }

    /**
     * The accepted words, from every shipped locale at once (an sk export in an en session).
     */
    private static function booleanWords(): BooleanWords
    {
        $key = app()->getLocale();

        if (isset(self::$booleans[$key])) {
            return self::$booleans[$key];
        }

        $lists = ['true' => [], 'false' => []];

        foreach (array_unique(['en', 'sk', ...ImportsConfig::headerLocales()]) as $locale) {
            $words = trans('imports::booleans', [], $locale);

            foreach (['true', 'false'] as $kind) {
                $list = is_array($words) && is_array($words[$kind] ?? null) ? $words[$kind] : [];

                foreach ($list as $word) {
                    if (is_string($word)) {
                        $lists[$kind][] = mb_strtolower($word);
                    }
                }
            }
        }

        return self::$booleans[$key] = new BooleanWords(array_values(array_unique($lists['true'])), array_values(array_unique($lists['false'])));
    }
}
