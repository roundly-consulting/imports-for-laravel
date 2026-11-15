<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Pipeline\KeyPart;
use RoundlyConsulting\Imports\Pipeline\KeyValues;
use RoundlyConsulting\Imports\Reading\Cell;

/**
 * A preview row's human label, from its cells as uploaded — never stored, so the row results keep
 * holding no row values. In order: the importer's `labelField()`, else its natural key (the
 * candidate the row matched by, else the first one the row fills; parts joined with " · ", a
 * reference shown as written), else "Row N". A blank mapped cell falls back to the field's
 * default, like the value pipeline does. At most 255 characters.
 *
 * @internal
 */
final readonly class RowLabels
{
    public const int MAX_LENGTH = 255;

    /**
     * @param  array<string, int>  $columns  field => the column index mapped to it
     * @param  array<string, Field>  $fields
     * @param  array<int, non-empty-list<KeyPart>>  $candidates
     */
    private function __construct(
        private array $columns,
        private array $fields,
        private ?string $labelField,
        private array $candidates,
    ) {}

    /**
     * @param  array<int, string>  $mapping  column index => field
     */
    public static function for(Importer $importer, array $mapping): self
    {
        $fields = [];

        foreach ($importer->fields() as $field) {
            $fields[$field->name] = $field;
        }

        $naturalKey = $importer->naturalKey();
        $fileKey = $importer->uniqueInFile();
        $candidates = $naturalKey === null ? [] : KeyPart::candidates($importer, $naturalKey);

        // The file-only key labels a row too, after the natural key; its candidates come after the
        // natural key's, so a stored match_candidate still names the same one.
        foreach ($fileKey === null ? [] : KeyPart::candidates($importer, $fileKey) as $parts) {
            $candidates[] = $parts;
        }

        return new self(
            array_flip($mapping),
            $fields,
            $importer->labelField(),
            $candidates,
        );
    }

    public static function fallback(int $rowNumber, string $locale): string
    {
        return Locales::line('imports::preview.row_label', ['number' => $rowNumber], $locale);
    }

    /**
     * @param  list<Cell|null>  $cells  the row as read
     * @param  int|null  $candidate  the natural-key candidate the row matched by, tried first
     */
    public function of(array $cells, ?int $candidate = null): ?string
    {
        if ($this->labelField !== null && ($text = $this->text($this->labelField, $cells)) !== null) {
            return self::cap($text);
        }

        $order = $this->candidates;

        if ($candidate !== null && isset($order[$candidate])) {
            $order = [$candidate => $order[$candidate]] + $order;
        }

        foreach ($order as $parts) {
            $texts = [];

            foreach ($parts as $part) {
                $text = $this->text($part->field, $cells);

                if ($text === null) {
                    continue 2;
                }

                $texts[] = $text;
            }

            return self::cap(implode(' · ', $texts));
        }

        return null;
    }

    /**
     * @param  list<Cell|null>  $cells
     */
    private function text(string $field, array $cells): ?string
    {
        $index = $this->columns[$field] ?? null;

        if ($index !== null) {
            $text = trim((string) ($cells[$index] ?? null)?->display());

            if ($text !== '') {
                return $text;
            }
        }

        $declared = $this->fields[$field] ?? null;

        return $declared !== null && $declared->hasDefault ? KeyValues::of($declared->default) : null;
    }

    private static function cap(string $text): string
    {
        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
