<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * How a file batch finds duplicates: by SHA-256 against this owner's earlier imports of the same
 * importer that were not rolled back (the default, plus within the batch), a host callback, or
 * not at all.
 */
final readonly class FileDuplicates
{
    /**
     * @param  'ledger'|'using'|'none'  $strategy
     * @param  (Closure(string, Model): ?Model)|null  $callback
     */
    private function __construct(
        public string $strategy,
        public ?Closure $callback = null,
    ) {}

    public static function ledger(): self
    {
        return new self('ledger');
    }

    /**
     * @param  Closure(string $sha256, Model $owner): ?Model  $callback
     */
    public static function using(Closure $callback): self
    {
        return new self('using', $callback);
    }

    public static function none(): self
    {
        return new self('none');
    }
}
