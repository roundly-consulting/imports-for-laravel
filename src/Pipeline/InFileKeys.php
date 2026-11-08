<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

/**
 * The natural keys (32-byte digests) and matched records valid rows have claimed so far — memory
 * O(rows), bounded by the row limit.
 *
 * @internal
 */
final class InFileKeys
{
    /** @var array<string, true> */
    private array $keys = [];

    /** @var array<string, true> */
    private array $records = [];

    /**
     * @param  list<string>  $digests
     */
    public function seenAny(array $digests): bool
    {
        foreach ($digests as $digest) {
            if (isset($this->keys[$digest])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $digests
     */
    public function add(array $digests, ?string $record): void
    {
        foreach ($digests as $digest) {
            $this->keys[$digest] = true;
        }

        if ($record !== null) {
            $this->records[$record] = true;
        }
    }

    public function seenRecord(string $record): bool
    {
        return isset($this->records[$record]);
    }
}
