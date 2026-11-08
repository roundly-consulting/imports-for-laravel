<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

use BackedEnum;
use DateTimeInterface;

/**
 * One field an update would change: from the record's value to the row's.
 */
final readonly class FieldChange
{
    public function __construct(
        public string $field,
        public ?string $from,
        public ?string $to,
    ) {}

    public static function comparable(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => json_encode($value) ?: null,
        };
    }
}
