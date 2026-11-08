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
 * Map columns to fields — by header text (`['Inventárne č.' => 'asset_tag']`) or column index
 * (`[3 => 'name']`; required for a duplicated header). An unknown field or column, a field mapped
 * twice or a required field left unmapped is refused with keyed errors. Any preview is dropped.
 */
final readonly class ApplyMappingAction
{
    public function __construct(private ImporterRegistry $registry) {}

    /**
     * @param  array<int|string, string|null>|ColumnMapping  $mapping
     */
    public function execute(ImportSession $session, array|ColumnMapping $mapping, ?Model $actor): ImportSession
    {
        $importer = $this->registry->rows($session->importer);
        Access::check($importer, $actor, Audits::owner($session), ImportOperation::Upload);
        SessionState::assertBeforeCommit($session);

        $resolved = $this->resolve($session, $importer, $mapping);

        SessionState::resetPreview($session);
        $session->forceFill([
            'mapping' => $resolved,
            'mapping_source' => MappingSource::Manual,
            'status' => SessionStatus::Mapped,
        ])->save();

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
        $input = $mapping instanceof ColumnMapping ? $mapping->toArray() : $mapping;
        $errors = [];
        $resolved = [];
        $line = static fn (string $code, string $placeholder, string $value): string => Locales::line('imports::exceptions.mapping.'.$code, [$placeholder => $value], $session->locale);

        foreach ($input as $column => $field) {
            if ($field === null || $field === '') {
                continue;
            }

            if (is_int($column)) {
                $index = $column;
            } else {
                $matches = array_keys(array_filter(
                    $headers,
                    static fn (?string $header, int $i): bool => $header === $column || $display[$i] === $column,
                    ARRAY_FILTER_USE_BOTH,
                ));

                if (count($matches) > 1) {
                    $errors['columns.'.$column][] = $line('ambiguous', 'column', $column);

                    continue;
                }

                $index = $matches[0] ?? null;
            }

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
