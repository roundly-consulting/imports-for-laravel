<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Enums\SessionStatus;

/**
 * English and Slovak ship the same keys and placeholders, every enum and rejection has a line,
 * and Slovak renders through the package namespace.
 *
 * @return list<string>
 */
function importsLangFiles(): array
{
    return array_map(static fn (string $path): string => basename($path, '.php'), glob(__DIR__.'/../../../resources/lang/en/*.php') ?: []);
}

/**
 * @return array<string, string>
 */
function importsLines(string $locale, string $file): array
{
    /** @var array<string, mixed> $lines */
    $lines = require __DIR__."/../../../resources/lang/{$locale}/{$file}.php";

    return array_map(static fn (mixed $line): string => (string) $line, Arr::dot($lines));
}

it('ships every English file in Slovak too', function (): void {
    expect(importsLangFiles())->toHaveCount(7);

    foreach (importsLangFiles() as $file) {
        expect(__DIR__."/../../../resources/lang/sk/{$file}.php")->toBeFile();
    }
});

it('ships the same keys and placeholders in both languages', function (): void {
    foreach (importsLangFiles() as $file) {
        $en = importsLines('en', $file);
        $sk = importsLines('sk', $file);

        expect(array_keys($sk))->toEqualCanonicalizing(array_keys($en));

        foreach ($en as $key => $line) {
            preg_match_all('/:[a-z_]+/', $line, $enPlaceholders);
            preg_match_all('/:[a-z_]+/', $sk[$key], $skPlaceholders);

            expect(array_unique($skPlaceholders[0]))->toEqualCanonicalizing(array_unique($enPlaceholders[0]), "{$file}.{$key}");
        }
    }
});

it('has a line for every rejection reason and enum case', function (): void {
    foreach (RejectionReason::cases() as $reason) {
        expect(__('imports::rejections.'.$reason->value))->not->toBe('imports::rejections.'.$reason->value);
    }

    app()->setLocale('sk');

    foreach (SessionStatus::cases() as $status) {
        expect($status->label())->not->toBe($status->value);
    }
});

it('renders Slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('imports::exceptions.not_found'))->toBe('Import sa nenašiel.')
        ->and(SessionStatus::Previewed->label())->toBe('Pripravený na kontrolu')
        ->and(__('imports::templates.required'))->toBe('Povinné');
});

it('covers every count in the update notice', function (int $count, string $en, string $sk): void {
    expect(trans_choice('imports::preview.update_notice', $count, ['count' => $count], 'en'))->toStartWith($en)
        ->and(trans_choice('imports::preview.update_notice', $count, ['count' => $count], 'sk'))->toStartWith($sk);
})->with([
    [0, '0 existing records will be updated.', 'Aktualizuje sa 0 existujúcich záznamov.'],
    [1, '1 existing record will be updated.', 'Aktualizuje sa 1 existujúci záznam.'],
    [3, '3 existing records will be updated.', 'Aktualizujú sa 3 existujúce záznamy.'],
    [5, '5 existing records will be updated.', 'Aktualizuje sa 5 existujúcich záznamov.'],
]);
