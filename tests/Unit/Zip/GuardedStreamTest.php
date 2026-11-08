<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Zip\ByteBudget;
use RoundlyConsulting\Imports\Zip\GuardedStream;

test('the_guarded_stream_fails_the_read_once_the_budget_trips', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, str_repeat('A', 100_000));
    rewind($stream);
    $budget = new ByteBudget(perPart: 10_000, total: PHP_INT_MAX);

    GuardedStream::attach($stream, $budget, 100);
    $read = '';

    while (! feof($stream)) {
        $chunk = @fread($stream, 8192);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $read .= $chunk;
    }

    fclose($stream);

    expect($budget->exceeded)->toBeTrue()
        ->and(strlen($read))->toBeLessThanOrEqual(10_000);
});

test('the_guarded_stream_passes_everything_within_budget', function (): void {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, str_repeat('B', 5000));
    rewind($stream);
    $budget = new ByteBudget(perPart: 10_000, total: PHP_INT_MAX);

    GuardedStream::attach($stream, $budget, 5000);

    expect(stream_get_contents($stream))->toBe(str_repeat('B', 5000))->and($budget->exceeded)->toBeFalse();
    fclose($stream);
});
