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

    /** @var array<string, int> file-only key digest => the first row that held it */
    private array $fileKeys = [];

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

    /**
     * The earlier row that holds any of these file-only key digests, or null.
     *
     * @param  list<string>  $digests
     */
    public function fileRowFor(array $digests): ?int
    {
        foreach ($digests as $digest) {
            if (isset($this->fileKeys[$digest])) {
                return $this->fileKeys[$digest];
            }
        }

        return null;
    }

    /**
     * Remember a row's file-only key digests; the first row holding a key keeps it.
     *
     * @param  list<string>  $digests
     */
    public function addFileKeys(array $digests, int $row): void
    {
        foreach ($digests as $digest) {
            $this->fileKeys[$digest] ??= $row;
        }
    }

    public function seenRecord(string $record): bool
    {
        return isset($this->records[$record]);
    }
}
