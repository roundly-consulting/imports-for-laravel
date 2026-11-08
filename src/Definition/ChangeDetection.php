<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

/**
 * How undo decides a created record was changed after the import (changed records are kept):
 * a checksum of its attributes (the default — catches same-second edits and quiet saves, ignores a
 * bare `touch()`), or its `updated_at`.
 */
final readonly class ChangeDetection
{
    /**
     * @param  'checksum'|'updated_at'  $strategy
     * @param  list<string>|null  $attributes
     */
    private function __construct(
        public string $strategy,
        public ?array $attributes = null,
    ) {}

    /**
     * @param  list<string>|null  $attributes  only these attributes (default: every attribute but the key and timestamps)
     */
    public static function checksum(?array $attributes = null): self
    {
        return new self('checksum', $attributes);
    }

    public static function updatedAt(): self
    {
        return new self('updated_at');
    }

    public function usesChecksum(): bool
    {
        return $this->strategy === 'checksum';
    }
}
