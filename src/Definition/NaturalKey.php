<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

/**
 * How a row finds the existing record it duplicates: an ordered list of candidates, each one or
 * more fields — `NaturalKey::by('asset_tag')->then('serial_number')`. A row's first filled
 * candidate that finds a record is its match.
 */
final readonly class NaturalKey
{
    /**
     * @param  non-empty-list<non-empty-list<string>>  $candidates
     */
    private function __construct(
        public array $candidates,
        public bool $caseInsensitive = false,
    ) {}

    public static function by(string $field, string ...$fields): self
    {
        return new self([[$field, ...array_values($fields)]]);
    }

    public function then(string $field, string ...$fields): self
    {
        return new self([...$this->candidates, [$field, ...array_values($fields)]], $this->caseInsensitive);
    }

    public function caseInsensitive(bool $caseInsensitive = true): self
    {
        return new self($this->candidates, $caseInsensitive);
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return array_values(array_unique(array_merge(...$this->candidates)));
    }
}
