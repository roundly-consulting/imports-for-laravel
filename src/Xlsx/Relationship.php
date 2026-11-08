<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Xlsx;

/**
 * One OPC relationship: its kind (the type URI's last segment) and the resolved target part.
 *
 * @internal
 */
final readonly class Relationship
{
    public function __construct(
        public string $type,
        public string $target,
    ) {}
}
