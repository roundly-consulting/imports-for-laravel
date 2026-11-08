<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Zip;

/**
 * Counts the real inflated bytes of every part one read opens — declared sizes are never
 * trusted. `perPart` caps one part, `total` all of them together, and `ratio` (when set) the
 * actual compression ratio of a part larger than `ratioMinSize`.
 *
 * @internal
 */
final class ByteBudget
{
    public int $used = 0;

    public int $part = 0;

    public int $compressed = 0;

    public bool $exceeded = false;

    public function __construct(
        public readonly int $perPart,
        public readonly int $total,
        public readonly ?int $ratio = null,
        public readonly int $ratioMinSize = 0,
    ) {}

    public function startPart(int $compressedSize): void
    {
        $this->part = 0;
        $this->compressed = max(1, $compressedSize);
    }

    /**
     * Account for `$bytes` more inflated bytes; false once any cap is crossed.
     */
    public function consume(int $bytes): bool
    {
        $this->part += $bytes;
        $this->used += $bytes;

        $overRatio = $this->ratio !== null
            && $this->part > $this->ratioMinSize
            && intdiv($this->part, $this->compressed) > $this->ratio;

        if ($this->part > $this->perPart || $this->used > $this->total || $overRatio) {
            $this->exceeded = true;
        }

        return ! $this->exceeded;
    }
}
