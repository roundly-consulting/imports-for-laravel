<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

/**
 * One cell after the value pipeline: the typed value, or the error code that stopped it.
 *
 * @internal
 */
final readonly class Coerced
{
    /**
     * @param  array<string, string|int>  $params
     */
    private function __construct(
        public mixed $value,
        public ?string $error = null,
        public array $params = [],
    ) {}

    public static function value(mixed $value): self
    {
        return new self($value);
    }

    /**
     * @param  array<string, string|int>  $params
     */
    public static function error(string $code, array $params = []): self
    {
        return new self(null, $code, $params);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
