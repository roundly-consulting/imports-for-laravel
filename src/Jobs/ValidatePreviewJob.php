<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Imports\Actions\Preview\ValidateRowsAction;
use RoundlyConsulting\Imports\Support\Durations;
use RoundlyConsulting\Imports\Support\ImportsConfig;

/**
 * A preview too large to validate in the request. Unique per session AND preview version: the
 * unique lock is held until the running job finishes, so a session-only key would silently drop
 * the job dispatched after a mapping change.
 *
 * @internal
 */
final class ValidatePreviewJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly int|string $sessionId,
        public readonly int $version,
    ) {
        $this->timeout = ImportsConfig::timeout();
    }

    /**
     * Held until the job finishes, at most until imports:sweep would give up on it anyway.
     */
    public function uniqueFor(): int
    {
        return Durations::seconds(ImportsConfig::abandonAfter());
    }

    public function uniqueId(): string
    {
        return $this->sessionId.':'.$this->version;
    }

    public function handle(ValidateRowsAction $action): void
    {
        $action->execute($this->sessionId, $this->version);
    }
}
