<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Enums\FieldType;
use RoundlyConsulting\Imports\Importer;

/**
 * One part of a natural-key candidate: the field a row reads it from and the model column a
 * record is compared on. A plain field compares its value in the same-named column; a reference
 * (named by the field or by its `storeAs()` column) compares the referenced record's key in its
 * `storeAs()` column — exactly, never case-folded.
 *
 * @internal
 */
final readonly class KeyPart
{
    public function __construct(
        public string $field,
        public string $column,
        public bool $reference,
    ) {}

    /**
     * Every candidate of the importer's natural key, resolved. A name the definition validator
     * would refuse resolves to a plain part of that name.
     *
     * @return array<int, non-empty-list<self>> candidate index => parts
     */
    public static function candidates(Importer $importer, NaturalKey $naturalKey): array
    {
        $fields = [];
        $storedAs = [];

        foreach ($importer->fields() as $field) {
            $fields[$field->name] = $field;

            if ($field->type === FieldType::Reference && $field->storeAs !== null) {
                $storedAs[$field->storeAs] = $field;
            }
        }

        $candidates = [];

        foreach ($naturalKey->candidates as $index => $names) {
            $candidates[$index] = array_map(static fn (string $name): self => self::resolve($name, $fields[$name] ?? $storedAs[$name] ?? null), $names);
        }

        return $candidates;
    }

    private static function resolve(string $name, ?Field $field): self
    {
        if ($field === null || $field->type !== FieldType::Reference) {
            return new self($name, $name, false);
        }

        return new self($field->name, $field->storeAs ?? $name, true);
    }
}
