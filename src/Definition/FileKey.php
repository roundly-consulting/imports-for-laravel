<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

/**
 * What makes a row unique within one file, without matching existing records:
 * `FileKey::by('email')->caseInsensitive()`. Normalised like a natural key — trimmed, lower-cased
 * when asked, a reference by its resolved record's key — and an ordered list of candidates
 * (`->then('phone')`): a later row that shares any filled candidate with an earlier one is a
 * duplicate within the file. Existing records are never looked up, active or soft-deleted.
 */
final readonly class FileKey
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
