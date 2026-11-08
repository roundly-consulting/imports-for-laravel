<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

/**
 * The lower-cased words a boolean cell may hold, from every shipped locale.
 *
 * @internal
 */
final readonly class BooleanWords
{
    /**
     * @param  list<string>  $true
     * @param  list<string>  $false
     */
    public function __construct(
        public array $true,
        public array $false,
    ) {}
}
