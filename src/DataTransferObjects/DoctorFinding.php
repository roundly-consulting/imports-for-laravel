<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * One `imports:doctor` check: ok, warning or error, with what to do.
 */
final readonly class DoctorFinding
{
    /**
     * @param  'ok'|'warning'|'error'  $level
     */
    public function __construct(
        public string $check,
        public string $level,
        public string $message,
    ) {}
}
