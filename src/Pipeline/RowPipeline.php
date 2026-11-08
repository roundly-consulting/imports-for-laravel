<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Rows\ImportRow;
use RoundlyConsulting\Imports\Support\Digests;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Templates\TemplateRows;

/**
 * Validates and classifies a window of rows: map → per-cell value pipeline → Laravel validation
 * (rules built once, labels as attribute names, messages in the session locale) → batched
 * reference lookups → batched natural-key matching → classification. It reads host tables only
 * through the owner scope and never writes or calls an importer callback.
 *
 * @internal
 */
final class RowPipeline
{
    /** @var array<string, list<mixed>> */
    private array $rules = [];

    /** @var array<string, string> */
    private array $attributes = [];

    /** @var array<string, Field> */
    private array $fields = [];

    /** @var array<int, string> column index => field */
    private array $columns;

    /** @var list<array<string, string>>|null */
    private ?array $templateExpectations = null;

    /** @var array<string, string|null> reference field => its storeAs() column */
    private array $storeAs = [];

    private readonly CellNormalizer $normalizer;

    /**
     * @param  array<int, string>  $mapping  column index => field
     */
    public function __construct(
        private readonly Importer $importer,
        array $mapping,
        private readonly int $headerCount,
        private readonly Model $owner,
        private readonly OwnerScope $scope,
        private readonly string $locale,
        string $timezone,
        private readonly ValidationFactory $validation,
        bool $date1904 = false,
    ) {
        $this->columns = $mapping;
        $this->normalizer = CellNormalizer::forSession($locale, $timezone, $date1904);

        foreach ($importer->fields() as $field) {
            $this->fields[$field->name] = $field;
            $this->attributes[$field->name] = $field->labelIn($locale);
            $this->rules[$field->name] = self::rulesFor($field);

            if ($field->type === FieldType::Reference) {
                $this->storeAs[$field->name] = $field->storeAs;
            }
        }
    }

    /**
     * @param  array<int, list<Cell|null>>  $rows  row number => cells
     * @param  list<int>  $templateWindow  the row numbers that may be the template's hint/example rows
     * @return list<RowEvaluation>
     */
    public function evaluate(array $rows, InFileKeys $seen, array $templateWindow = []): array
    {
        return Locales::using($this->locale, function () use ($rows, $seen, $templateWindow): array {
            $evaluations = [];
            $window = array_fill_keys($templateWindow, true);

            foreach ($rows as $number => $cells) {
                $evaluations[] = $this->row($number, $cells, isset($window[$number]));
            }

            $this->resolveReferences($evaluations);
            $this->match($evaluations, $seen);

            foreach ($evaluations as $evaluation) {
                if ($evaluation->isValid() && ! $evaluation->templateRow) {
                    $evaluation->row = $this->importRow($evaluation);
                }
            }

            return $evaluations;
        });
    }

    public static function outcome(RowStatus $status, DuplicateMode $mode): RowOutcome
    {
        return match ($status) {
            RowStatus::Valid => RowOutcome::Create,
            RowStatus::Matched => match ($mode) {
                DuplicateMode::Skip => RowOutcome::Skip,
                DuplicateMode::Update => RowOutcome::Update,
                DuplicateMode::CreateNew => RowOutcome::Create,
            },
            default => RowOutcome::Skip,
        };
    }

    /**
     * @param  list<Cell|null>  $cells
     */
    private function row(int $number, array $cells, bool $mayBeTemplate): RowEvaluation
    {
        $mapped = [];

        foreach ($this->columns as $index => $name) {
            $mapped[$name] = $cells[$index] ?? null;
        }

        $evaluation = new RowEvaluation($number, $mapped, $cells);

        if ($mayBeTemplate && TemplateRows::matches($this->templateExpectations ??= TemplateRows::expectations($this->fields), $mapped)) {
            $evaluation->templateRow = true;

            return $evaluation;
        }

        foreach ($cells as $index => $cell) {
            if ($cell !== null && $index >= $this->headerCount) {
                $evaluation->fail(null, $index, 'extra_values', $this->message('extra_values', ['count' => count($cells) - $this->headerCount]), $cell->display());

                break;
            }
        }

        $values = [];
        $rules = [];

        foreach ($this->fields as $name => $field) {
            $column = array_search($name, $this->columns, true);
            $cell = $mapped[$name] ?? null;

            if ($column === false) {
                if ($field->hasDefault) {
                    $values[$name] = $field->default;
                }

                continue;
            }

            $coerced = $this->normalizer->normalize($field, $cell);

            if ($coerced->failed()) {
                $evaluation->fail($name, $column, (string) $coerced->error, $this->message((string) $coerced->error, [...$coerced->params, 'attribute' => $this->attributes[$name], 'value' => mb_substr((string) $cell?->display(), 0, 100)]), $cell?->display());

                continue;
            }

            $values[$name] = $coerced->value;
            $rules[$name] = $this->rules[$name];
        }

        $validator = $this->validation->make($values, $rules, [], $this->attributes);

        if ($validator->fails()) {
            $failed = $validator->failed();

            foreach ($validator->errors()->messages() as $name => $messages) {
                $column = array_search($name, $this->columns, true);
                $rules = $failed[$name] ?? [];
                $rule = is_array($rules) ? array_key_first($rules) : null;
                $evaluation->fail($name, $column === false ? null : $column, is_string($rule) ? strtolower(class_basename($rule)) : 'invalid', (string) ($messages[0] ?? ''), ($mapped[$name] ?? null)?->display());
            }
        }

        $evaluation->values = $values;

        return $evaluation;
    }

    /**
     * @param  list<RowEvaluation>  $evaluations
     */
    private function resolveReferences(array $evaluations): void
    {
        foreach ($this->fields as $name => $field) {
            if ($field->type !== FieldType::Reference || ! in_array($name, $this->columns, true)) {
                continue;
            }

            $values = [];

            foreach ($evaluations as $evaluation) {
                $value = $evaluation->values[$name] ?? null;

                if (! $evaluation->templateRow && is_string($value) && $value !== '') {
                    $values[] = $value;
                }
            }

            $resolved = $values === [] ? [] : ReferenceResolver::resolve($field, array_values(array_unique($values)), $this->owner, $this->ruleFor($field), $this->scope);
            $column = array_search($name, $this->columns, true);

            foreach ($evaluations as $evaluation) {
                $value = $evaluation->values[$name] ?? null;

                if (! is_string($value) || $value === '' || $evaluation->templateRow) {
                    $evaluation->references[$name] = null;

                    continue;
                }

                $result = $resolved[$field->caseInsensitive ? mb_strtolower($value) : $value] ?? ReferenceResolver::UNKNOWN;

                if ($result instanceof Model) {
                    $evaluation->references[$name] = $result;

                    continue;
                }

                $evaluation->references[$name] = null;
                $code = $result === ReferenceResolver::AMBIGUOUS ? 'ambiguous_reference' : 'unknown_reference';
                $evaluation->fail($name, $column === false ? null : $column, $code, $this->message($code, ['attribute' => $this->attributes[$name], 'value' => mb_substr($value, 0, 100)]), $value);
            }
        }
    }

    /**
     * @param  list<RowEvaluation>  $evaluations
     */
    private function match(array $evaluations, InFileKeys $seen): void
    {
        $naturalKey = $this->importer->naturalKey();

        if ($naturalKey === null) {
            return;
        }

        /** @var array<int, array<int, array<string, string>>> $rowKeys row index => candidate => tuple */
        $rowKeys = [];
        $tuples = [];

        foreach ($evaluations as $position => $evaluation) {
            if (! $evaluation->isValid() || $evaluation->templateRow) {
                continue;
            }

            foreach ($naturalKey->candidates as $candidate => $fields) {
                $tuple = [];

                foreach ($fields as $field) {
                    $value = KeyValues::of($evaluation->values[$field] ?? null, $naturalKey->caseInsensitive);

                    if ($value === null) {
                        continue 2;
                    }

                    $tuple[$field] = $value;
                }

                $rowKeys[$position][$candidate] = $tuple;
                $tuples[$candidate][implode("\x1f", $tuple)] = $tuple;
            }
        }

        $found = $tuples === [] ? [] : NaturalKeyMatcher::find(
            $this->importer->model(),
            $naturalKey,
            array_map(array_values(...), $tuples),
            $this->owner,
            $this->importer->ownership(),
            $this->scope,
        );

        foreach ($evaluations as $position => $evaluation) {
            if (! isset($rowKeys[$position])) {
                continue;
            }

            $digests = [];
            $match = null;
            $deleted = null;

            foreach ($rowKeys[$position] as $candidate => $tuple) {
                $key = implode("\x1f", $tuple);
                $digests[] = Digests::raw($candidate."\x1e".$key);
                $all = $found[$candidate][$key] ?? [];
                $records = array_values(array_filter($all, static fn (Model $record): bool => ! self::trashed($record)));

                if ($records === [] && $all !== []) {
                    $deleted ??= $key;

                    continue;
                }

                if (count($records) > 1) {
                    $evaluation->fail(null, null, 'ambiguous_match', $this->message('ambiguous_match', ['key' => $key]), $key);

                    break;
                }

                if (count($records) === 1) {
                    if ($match === null) {
                        $match = $records[0];
                        $evaluation->matchKey = mb_substr($key, 0, 64);
                        $evaluation->matchCandidate = $candidate;
                    } elseif ($match->getKey() !== $records[0]->getKey()) {
                        $evaluation->fail(null, null, 'conflicting_match', $this->message('conflicting_match', ['key' => $key]), $key);

                        break;
                    }
                }
            }

            // Only a soft-deleted record holds this key: a create would hit the host's unique index,
            // an update or skip would target a deleted record — the row is refused, in every mode.
            if ($evaluation->isValid() && $match === null && $deleted !== null) {
                $evaluation->fail(null, null, 'matches_deleted_record', $this->message('matches_deleted_record', ['key' => $deleted]), $deleted);
            }

            if (! $evaluation->isValid()) {
                $evaluation->matchKey = null;
                $evaluation->matchCandidate = null;

                continue;
            }

            $recordKey = $match === null ? null : $match::class."\x1f".Digests::keyString($match->getKey());

            if ($seen->seenAny($digests)) {
                $evaluation->status = RowStatus::DuplicateInFile;
                $evaluation->duplicateReason = 'duplicate_in_file';
            } elseif ($recordKey !== null && $seen->seenRecord($recordKey)) {
                $evaluation->status = RowStatus::DuplicateInFile;
                $evaluation->duplicateReason = 'same_record';
            } else {
                $seen->add($digests, $recordKey);
                $evaluation->match = $match;
                $evaluation->status = $match === null ? RowStatus::Valid : RowStatus::Matched;
            }
        }
    }

    private static function trashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed() === true;
    }

    private function importRow(RowEvaluation $evaluation): ImportRow
    {
        $raw = [];

        foreach ($evaluation->cells as $name => $cell) {
            $raw[$name] = $cell?->display();
        }

        return new ImportRow(
            $evaluation->number,
            $evaluation->values,
            $raw,
            $evaluation->cells,
            $evaluation->references,
            array_values(array_unique($this->columns)),
            $this->storeAs,
        );
    }

    private function ruleFor(Field $field): Ownership
    {
        return $field->ownership->kind === 'inherit' ? $this->importer->ownership() : $field->ownership;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    private function message(string $code, array $params = []): string
    {
        return Locales::line('imports::validation.'.$code, $params, $this->locale);
    }

    /**
     * @return list<mixed>
     */
    private static function rulesFor(Field $field): array
    {
        $rules = [$field->required ? 'required' : 'nullable'];

        $rules = [...$rules, ...match ($field->type) {
            FieldType::Text => array_filter(['string', $field->max !== null ? 'max:'.$field->max : null, $field->min !== null ? 'min:'.$field->min : null]),
            FieldType::Email => ['string', 'email:rfc'],
            FieldType::Integer, FieldType::Decimal => array_filter(['numeric', $field->max !== null ? 'max:'.$field->max : null, $field->min !== null ? 'min:'.$field->min : null]),
            default => [],
        }];

        foreach ($field->rules as $rule) {
            $rules[] = $rule instanceof Closure ? $rule : $rule;
        }

        return $rules;
    }
}
