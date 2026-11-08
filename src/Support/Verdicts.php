<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Closure;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Exceptions\ImportUnauthorizedException;

/**
 * The authorize() verdicts one handle already got, per operation: a polling loop on one handle asks
 * the host's callback once. A handle is immutable, so the cache lives in this small mutable object.
 *
 * @internal
 */
final class Verdicts
{
    /** @var array<string, bool> */
    private array $verdicts = [];

    /**
     * @param  Closure(): bool  $ask  the authorize() call, made only when no verdict is cached
     */
    public function check(ImportOperation $operation, Closure $ask): void
    {
        $this->verdicts[$operation->value] ??= $ask();

        if (! $this->verdicts[$operation->value]) {
            throw ImportUnauthorizedException::operation($operation->value);
        }
    }
}
