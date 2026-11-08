<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

/**
 * The queue an import's jobs run on: the configured connection and queue, or `sync` with the
 * queue switched off.
 *
 * @internal
 */
final class Queues
{
    public static function connection(): ?string
    {
        return ImportsConfig::queueEnabled() ? ImportsConfig::queueConnection() : 'sync';
    }

    public static function queue(): ?string
    {
        return ImportsConfig::queueEnabled() ? ImportsConfig::queueName() : null;
    }
}
