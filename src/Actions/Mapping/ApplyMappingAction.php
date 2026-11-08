<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Actions\Mapping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Imports\DataTransferObjects\ColumnMapping;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\MappingSource;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Events\ImportMapped;
use RoundlyConsulting\Imports\Exceptions\InvalidMappingException;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Locales;
use RoundlyConsulting\Imports\Support\SessionState;

/**
 * Map columns to fields — by header text (`['Inventárne č.' => 'asset_tag']`) or 0-based column
 * index (`[3 => 'name']`; required for a duplicated header). PHP turns a numeric key into an int,
 * so an int key that a header reads as (`'2024'`) maps that header; one that is a header *and* the
 * index of another column is refused rather than guessed. A `ColumnMapping` is always by index.
 * An unknown field or column, a field mapped twice or a required field left unmapped is refused
 * with keyed errors. Any preview is dropped.
 */
final readonly class ApplyMappingAction
{
    public function __construct(private ImporterRegistry $registry) {}

    /**
     * @param  array<int|string, string|null>|ColumnMapping  $mapping
     */
    public function execute(ImportSession $session, array|ColumnMapping $mapping, ?Model $actor): ImportSession
    {
        $session->refresh();
        $importer = $this->registry->rows($session->importer);
        Access::check($importer, $actor, Audits::owner($session), ImportOperation::Upload);
        SessionState::assertBeforeCommit($session);

        $resolved = $this->resolve($session, $importer, $mapping);

        SessionState::transition($session, static function () use ($session, $resolved): void {
            $session->forceFill([
                'mapping' => $resolved,
                'mapping_source' => MappingSource::Manual,
                'status' => SessionStatus::Mapped,
            ])->save();
        }, resetPreview: true);

        Event::dispatch(new ImportMapped(Audits::for($session, null, $actor)));

        return $session;
    }

    /**
     * @param  array<int|string, string|null>|ColumnMapping  $mapping
     * @return array<int, string>
     */
    private function resolve(ImportSession $session, Importer $importer, array|ColumnMapping $mapping): array
    {
        $headers = $session->headers;
        $display = SessionState::displayHeaders($session);
        $byIndex = $mapping instanceof ColumnMapping;
        $input = $mapping instanceof ColumnMapping ? $mapping->toArray() : $mapping;
        $errors = [];
        $resolved = [];
        $line = static fn (string $code, string $placeholder, string $value): string => Locales::line('imports::exceptions.mapping.'.$code, [$placeholder => $value], $session->locale);

        foreach ($input as $column => $field) {
            if ($field === null || $field === '') {
                continue;
            }

            $matches = $byIndex ? [] : array_keys(array_filter(
                $headers,
                static fn (?string $header, int $i): bool => $header === (string) $column || $display[$i] === (string) $column,
                ARRAY_FILTER_USE_BOTH,
            ));

            if (count($matches) > 1) {
                $errors['columns.'.$column][] = $line('ambiguous', 'column', (string) $column);

                continue;
            }

            if (is_int($column) && $matches !== [] && $matches[0] !== $column && $column >= 0 && $column < count($headers)) {
                $errors['columns.'.$column][] = $line('header_or_index', 'column', (string) $column);

                continue;
            }

            $index = $matches[0] ?? (is_int($column) ? $column : null);

            if ($index === null || $index < 0 || $index >= count($headers)) {
                $errors['columns.'.$column][] = $line('unknown_column', 'column', (string) $column);

                continue;
            }

            if ($importer->field($field) === null) {
                $errors['fields.'.$field][] = $line('unknown_field', 'field', $field);

                continue;
            }

            if (in_array($field, $resolved, true)) {
                $errors['fields.'.$field][] = $line('mapped_twice', 'field', $field);

                continue;
            }

            $resolved[$index] = $field;
        }

        foreach ($importer->fields() as $field) {
            if ($field->required && ! $field->hasDefault && ! in_array($field->name, $resolved, true)) {
                $errors['fields.'.$field->name][] = $line('required_unmapped', 'field', $field->labelIn($session->locale));
            }
        }

        if ($errors !== []) {
            throw InvalidMappingException::withErrors($errors);
        }

        ksort($resolved);

        return $resolved;
    }
}
