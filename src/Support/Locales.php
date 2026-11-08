<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use Closure;

/**
 * Host-supplied text — labels, help, option labels — is a translation key (or plain text), or a
 * locale map `['en' => …, 'sk' => …]`. Resolved in the requested locale, then the app fallback.
 *
 * @internal
 */
final class Locales
{
    /**
     * @param  string|array<string, string>|null  $spec
     */
    public static function text(string|array|null $spec, ?string $locale = null): ?string
    {
        if ($spec === null || $spec === '' || $spec === []) {
            return null;
        }

        $locale ??= self::current();

        if (is_array($spec)) {
            $fallback = self::fallback();

            return $spec[$locale] ?? $spec[$fallback] ?? (reset($spec) ?: null);
        }

        $line = trans($spec, [], $locale);

        return is_string($line) && $line !== '' ? $line : $spec;
    }

    public static function current(): string
    {
        $locale = app()->getLocale();

        return $locale === '' ? 'en' : $locale;
    }

    public static function fallback(): string
    {
        $fallback = config('app.fallback_locale');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'en';
    }

    /**
     * Every locale header matching tries: the configured ones, the current and the fallback.
     *
     * @return list<string>
     */
    public static function matching(?string $locale = null): array
    {
        return array_values(array_unique([...ImportsConfig::headerLocales(), $locale ?? self::current(), self::fallback()]));
    }

    /**
     * A package line, always a string.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public static function line(string $key, array $replace = [], ?string $locale = null): string
    {
        $line = trans($key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }

    /**
     * Run `$callback` with the app locale switched to `$locale` (validation messages, labels).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function using(string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();

        if ($previous === $locale) {
            return $callback();
        }

        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
