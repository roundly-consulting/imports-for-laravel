<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\Rule as LegacyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Support\Locales;
use Stringable;

/**
 * One column an importer understands. Fluent and immutable — every call returns a copy:
 *
 * ```php
 * Field::text('asset_tag')->label('app.asset.tag')->aliases('tag', 'inventory no')->example('AST-0042');
 * Field::choice('status')->options(AssetStatus::class)->default('active');
 * Field::reference('vendor')->to(Vendor::class)->by('vat_id', 'name')->storeAs('vendor_id');
 * ```
 *
 * Every value goes through the same pipeline: trim → blank is null → `normalizeUsing()` → type
 * coercion → `default()` → `rules()` → reference lookup.
 */
final class Field
{
    /** @var string|array<string, string>|null */
    public private(set) string|array|null $label = null;

    /** @var string|array<string, string>|null */
    public private(set) string|array|null $help = null;

    public private(set) bool $required = false;

    public private(set) mixed $default = null;

    public private(set) bool $hasDefault = false;

    public private(set) mixed $example = null;

    /** @var array<string, list<string>> locale (`*` = every locale) => aliases */
    public private(set) array $aliases = [];

    /** @var list<string|Stringable|ValidationRule|LegacyRule|Closure> */
    public private(set) array $rules = [];

    public private(set) ?Closure $normalizer = null;

    public private(set) int|float|null $max = null;

    public private(set) int|float|null $min = null;

    /** @var list<string> */
    public private(set) array $formats = [];

    /** @var list<string> */
    public private(set) array $trueValues = [];

    /** @var list<string> */
    public private(set) array $falseValues = [];

    /** @var array<string, string|array<string, string>> value => label (a translation key or a locale map) */
    public private(set) array $options = [];

    /** @var class-string<BackedEnum>|null */
    public private(set) ?string $enum = null;

    public private(set) bool $inTemplate = true;

    public private(set) int $scale = 2;

    /** @var class-string<Model>|null */
    public private(set) ?string $target = null;

    /** @var list<string> */
    public private(set) array $lookup = [];

    public private(set) Ownership $ownership;

    public private(set) bool $caseInsensitive = false;

    /** The foreign-key column a reference fills in `ImportRow::attributes()`. */
    public private(set) ?string $storeAs = null;

    private function __construct(
        public private(set) string $name,
        public private(set) FieldType $type,
    ) {
        $this->ownership = Ownership::inherit();
    }

    public static function text(string $name): self
    {
        return new self($name, FieldType::Text);
    }

    public static function integer(string $name): self
    {
        return new self($name, FieldType::Integer);
    }

    public static function decimal(string $name, int $scale = 2): self
    {
        $field = new self($name, FieldType::Decimal);
        $field->scale = max(0, min(10, $scale));

        return $field;
    }

    public static function boolean(string $name): self
    {
        return new self($name, FieldType::Boolean);
    }

    public static function date(string $name): self
    {
        return new self($name, FieldType::Date);
    }

    public static function datetime(string $name): self
    {
        return new self($name, FieldType::DateTime);
    }

    public static function email(string $name): self
    {
        return new self($name, FieldType::Email);
    }

    public static function choice(string $name): self
    {
        return new self($name, FieldType::Choice);
    }

    public static function reference(string $name): self
    {
        return new self($name, FieldType::Reference);
    }

    /**
     * @param  string|array<string, string>  $label  a translation key / text, or a locale map
     */
    public function label(string|array $label): self
    {
        return $this->with(static function (self $field) use ($label): void {
            $field->label = $label;
        });
    }

    /**
     * @param  string|array<string, string>  $help
     */
    public function help(string|array $help): self
    {
        return $this->with(static function (self $field) use ($help): void {
            $field->help = $help;
        });
    }

    public function required(bool $required = true): self
    {
        return $this->with(static function (self $field) use ($required): void {
            $field->required = $required;
        });
    }

    public function nullable(): self
    {
        return $this->required(false);
    }

    public function default(mixed $value): self
    {
        return $this->with(static function (self $field) use ($value): void {
            $field->default = $value;
            $field->hasDefault = true;
        });
    }

    public function example(mixed $value): self
    {
        return $this->with(static function (self $field) use ($value): void {
            $field->example = $value;
        });
    }

    /**
     * Extra header names that map to this field: plain, or per locale (`['sk' => ['Dodávateľ']]`).
     *
     * @param  string|array<string, list<string>>  $aliases
     */
    public function aliases(string|array $aliases, string ...$more): self
    {
        $map = is_array($aliases) ? $aliases : ['*' => [$aliases, ...array_values($more)]];

        return $this->with(static function (self $field) use ($map): void {
            foreach ($map as $locale => $list) {
                $field->aliases[(string) $locale] = [...($field->aliases[(string) $locale] ?? []), ...$list];
            }
        });
    }

    public function rules(string|Stringable|ValidationRule|LegacyRule|Closure ...$rules): self
    {
        return $this->with(static function (self $field) use ($rules): void {
            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    foreach (explode('|', $rule) as $part) {
                        if ($part !== '') {
                            $field->rules[] = $part;
                        }
                    }

                    continue;
                }

                $field->rules[] = $rule;
            }
        });
    }

    /**
     * @param  Closure(mixed $value, ?Cell $cell): mixed  $normalizer  runs after trim, before type coercion
     */
    public function normalizeUsing(Closure $normalizer): self
    {
        return $this->with(static function (self $field) use ($normalizer): void {
            $field->normalizer = $normalizer;
        });
    }

    public function max(int|float $max): self
    {
        return $this->with(static function (self $field) use ($max): void {
            $field->max = $max;
        });
    }

    public function min(int|float $min): self
    {
        return $this->with(static function (self $field) use ($min): void {
            $field->min = $min;
        });
    }

    /**
     * Extra date formats (PHP `createFromFormat` syntax), tried after `values.date_formats`.
     */
    public function formats(string ...$formats): self
    {
        return $this->with(static function (self $field) use ($formats): void {
            $field->formats = [...$field->formats, ...array_values($formats)];
        });
    }

    public function trueValues(string ...$values): self
    {
        return $this->with(static function (self $field) use ($values): void {
            $field->trueValues = [...$field->trueValues, ...array_values($values)];
        });
    }

    public function falseValues(string ...$values): self
    {
        return $this->with(static function (self $field) use ($values): void {
            $field->falseValues = [...$field->falseValues, ...array_values($values)];
        });
    }

    /**
     * The allowed values: a `value => label` map (or a list of values), or a backed enum (labels
     * from enums-for-laravel's `label()` when the enum has it). A map with keys 0, 1, 2, … is a
     * list: its labels are the values.
     *
     * @param  array<int|string, string|array<string, string>>|class-string<BackedEnum>  $options
     */
    public function options(array|string $options): self
    {
        return $this->with(static function (self $field) use ($options): void {
            if (is_string($options)) {
                $field->enum = $options;
                $field->options = [];

                foreach ($options::cases() as $case) {
                    $field->options[(string) $case->value] = self::caseLabel($case);
                }

                return;
            }

            $field->options = [];
            // Only a list is values without labels: `[1 => 'Male', 2 => 'Female']` keeps its codes.
            $list = array_is_list($options);

            foreach ($options as $value => $label) {
                if ($list && is_string($label)) {
                    $field->options[$label] = $label;
                } else {
                    $field->options[(string) $value] = $label;
                }
            }
        });
    }

    public function excludeFromTemplate(): self
    {
        return $this->with(static function (self $field): void {
            $field->inTemplate = false;
        });
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function to(string $model): self
    {
        return $this->with(static function (self $field) use ($model): void {
            $field->target = $model;
        });
    }

    /**
     * The columns of the referenced model a value is looked up by, tried in order.
     */
    public function by(string $column, string ...$columns): self
    {
        return $this->with(static function (self $field) use ($column, $columns): void {
            $field->lookup = [$column, ...array_values($columns)];
        });
    }

    public function ownedBy(Ownership $ownership): self
    {
        return $this->with(static function (self $field) use ($ownership): void {
            $field->ownership = $ownership;
        });
    }

    /**
     * The column the referenced record's key goes into: `attributes()` and `changes()` hold
     * `vendor_id => 5` instead of the `vendor` field. Without it, a reference is left out of both.
     */
    public function storeAs(string $column): self
    {
        return $this->with(static function (self $field) use ($column): void {
            $field->storeAs = $column;
        });
    }

    public function caseInsensitive(bool $caseInsensitive = true): self
    {
        return $this->with(static function (self $field) use ($caseInsensitive): void {
            $field->caseInsensitive = $caseInsensitive;
        });
    }

    // Reading ----------------------------------------------------------------------------------

    public function labelIn(?string $locale = null): string
    {
        return Locales::text($this->label, $locale) ?? $this->name;
    }

    public function helpIn(?string $locale = null): ?string
    {
        return Locales::text($this->help, $locale);
    }

    /**
     * @return list<string>
     */
    public function aliasesIn(string $locale): array
    {
        return [...($this->aliases['*'] ?? []), ...($this->aliases[$locale] ?? [])];
    }

    public function optionLabel(string $value, ?string $locale = null): string
    {
        $label = $this->options[$value] ?? $value;

        return Locales::text($label, $locale) ?? $value;
    }

    /**
     * The value a template's example row shows.
     */
    public function exampleValue(): mixed
    {
        if ($this->example !== null) {
            return $this->example;
        }

        return match ($this->type) {
            FieldType::Text => 'Text',
            FieldType::Integer => 1,
            FieldType::Decimal => '1.'.str_repeat('0', $this->scale),
            FieldType::Boolean => 'yes',
            FieldType::Date => '2026-01-15',
            FieldType::DateTime => '2026-01-15 09:30',
            FieldType::Email => 'jane@example.com',
            FieldType::Choice => array_key_first($this->options) ?? '',
            FieldType::Reference => '',
        };
    }

    private static function caseLabel(BackedEnum $case): string
    {
        if (method_exists($case, 'label')) {
            $label = $case->label();

            if (is_string($label)) {
                return $label;
            }
        }

        return $case->name;
    }

    private function with(Closure $change): self
    {
        $copy = clone $this;
        $change($copy);

        return $copy;
    }
}
