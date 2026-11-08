<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Fail fast before any write: a model whose key cannot be stored in the configured morph column
 * throws naming the config key and the model's basename, instead of a raw SQL error.
 *
 * @internal
 */
final class KeyTypes
{
    /**
     * @param  'owner'|'actor'|'record'  $role
     */
    public static function assert(?Model $model, string $role): void
    {
        if ($model === null) {
            return;
        }

        $type = $role === 'record' ? ImportsConfig::recordKeyType() : ImportsConfig::keyType();

        if (! self::fits($model->getKey(), $type)) {
            throw InvalidImportsConfigurationException::keyTypeMismatch(ImportsConfig::keyTypeKeyFor($role), class_basename($model), $type->value);
        }
    }

    public static function fits(mixed $key, KeyType $type): bool
    {
        return match ($type) {
            KeyType::BigInt => is_int($key) && $key > 0 || (is_string($key) && self::isBigInt($key)),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };
    }

    public static function isBigInt(string $value): bool
    {
        return preg_match('/^[1-9]\d{0,18}$/', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
