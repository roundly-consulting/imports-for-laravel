<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * Which files a file importer accepts: an extension allow-list and a size cap (KB).
 */
final readonly class FileRules
{
    /**
     * @param  list<string>  $extensions  lower-case, no dot; empty = any
     */
    private function __construct(
        public array $extensions,
        public ?int $maxSizeKb = null,
    ) {}

    public static function extensions(string ...$extensions): self
    {
        return new self(array_values(array_map(static fn (string $e): string => strtolower(ltrim($e, '.')), $extensions)));
    }

    public static function any(): self
    {
        return new self([]);
    }

    public function maxSize(int $kilobytes): self
    {
        return new self($this->extensions, max(1, $kilobytes));
    }

    public function maxBytes(): int
    {
        return ($this->maxSizeKb ?? ImportsConfig::maxFileSizeForFilesKb()) * 1024;
    }

    public function allows(string $extension): bool
    {
        return $this->extensions === [] || in_array(strtolower($extension), $this->extensions, true);
    }
}
