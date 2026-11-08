<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Mapping;

use RoundlyConsulting\Imports\DataTransferObjects\MappingSuggestion;
use RoundlyConsulting\Imports\DataTransferObjects\SavedMapping;
use RoundlyConsulting\Imports\DataTransferObjects\SuggestedColumn;
use RoundlyConsulting\Imports\Enums\MappingSource;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Support\ImportsConfig;
use RoundlyConsulting\Imports\Support\Locales;

/**
 * Suggests which column feeds which field: a saved mapping for this exact header row first, then
 * per column the field name (1.00), a label in any matching locale (0.98), an alias (0.95) and —
 * flagged for review — a fuzzy match. Greedy by score, each field once, ties to the leftmost
 * column.
 *
 * @internal
 */
final class Suggester
{
    /**
     * @param  list<string|null>  $headers
     * @param  list<string>  $display
     */
    public static function suggest(Importer $importer, array $headers, array $display, string $locale, ?SavedMapping $saved = null): MappingSuggestion
    {
        $normalized = HeaderNormalizer::all($headers, $locale);
        $fields = $importer->fields();
        $assigned = [];

        if ($saved !== null && ! HeaderNormalizer::collide($headers, $locale)) {
            $known = array_flip(array_map(static fn ($f): string => $f->name, $fields));

            foreach ($normalized as $index => $header) {
                $field = $saved->columns[$header] ?? null;

                if ($field !== null && isset($known[$field]) && ! in_array($field, $assigned, true)) {
                    $assigned[$index] = $field;
                }
            }

            return self::result($importer, $display, array_map(static fn (string $f): array => [$f, 1.0, MappingSource::Saved], $assigned), $saved->name);
        }

        $candidates = [];
        $fuzzy = ImportsConfig::fuzzy();
        $threshold = ImportsConfig::fuzzyThreshold();
        $locales = Locales::matching($locale);

        foreach ($normalized as $index => $header) {
            if ($header === '') {
                continue;
            }

            foreach ($fields as $field) {
                $best = null;

                if ($header === $field->name) {
                    $best = [1.0, MappingSource::Name];
                }

                foreach ($locales as $candidateLocale) {
                    if ($best === null && $header === HeaderNormalizer::normalize($field->labelIn($candidateLocale), $candidateLocale)) {
                        $best = [0.98, MappingSource::Label];
                    }

                    foreach ($field->aliasesIn($candidateLocale) as $alias) {
                        if ($best === null && $header === HeaderNormalizer::normalize($alias, $candidateLocale)) {
                            $best = [0.95, MappingSource::Alias];
                        }
                    }
                }

                if ($best === null && $fuzzy) {
                    $score = 0.0;

                    foreach ([$field->name, ...array_map(static fn (string $l): string => HeaderNormalizer::normalize($field->labelIn($l), $l), $locales)] as $target) {
                        similar_text($header, $target, $percent);
                        $score = max($score, $percent / 100);
                    }

                    if ($score >= $threshold) {
                        $best = [round($score, 4), MappingSource::Fuzzy];
                    }
                }

                if ($best !== null) {
                    $candidates[] = [$index, $field->name, $best[0], $best[1]];
                }
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b[2], $a[0]] <=> [$a[2], $b[0]]);
        $picked = [];

        foreach ($candidates as [$index, $field, $score, $source]) {
            if (isset($picked[$index]) || in_array($field, array_column($picked, 0), true)) {
                continue;
            }

            $picked[$index] = [$field, $score, $source];
        }

        return self::result($importer, $display, $picked, null);
    }

    /**
     * @param  list<string>  $display
     * @param  array<int, array{0: string, 1: float, 2: MappingSource}>  $picked
     */
    private static function result(Importer $importer, array $display, array $picked, ?string $saved): MappingSuggestion
    {
        $columns = [];
        $ignored = [];

        foreach ($display as $index => $header) {
            if (isset($picked[$index])) {
                $columns[] = new SuggestedColumn($index, $header, $picked[$index][0], $picked[$index][1], $picked[$index][2]);
            } else {
                $columns[] = new SuggestedColumn($index, $header, null, 0.0, null);
                $ignored[] = $index;
            }
        }

        $mapped = array_column($picked, 0);
        $missing = [];

        foreach ($importer->fields() as $field) {
            if ($field->required && ! $field->hasDefault && ! in_array($field->name, $mapped, true)) {
                $missing[] = $field->name;
            }
        }

        return new MappingSuggestion($columns, $missing, $ignored, $saved);
    }
}
