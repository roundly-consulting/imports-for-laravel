<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Zip\ByteBudget;

test('the_byte_budget_trips_on_the_part_cap', function (): void {
    $budget = new ByteBudget(perPart: 100, total: 1000);
    $budget->startPart(50);

    expect($budget->consume(100))->toBeTrue()
        ->and($budget->consume(1))->toBeFalse()
        ->and($budget->exceeded)->toBeTrue();

    $budget->startPart(50);   // a new part does not reset a crossed budget
    expect($budget->consume(1))->toBeFalse();
});

test('the_byte_budget_trips_on_the_total_cap', function (): void {
    $budget = new ByteBudget(perPart: 100, total: 150);
    $budget->startPart(100);
    expect($budget->consume(90))->toBeTrue();

    $budget->startPart(100);
    expect($budget->consume(60))->toBeTrue()
        ->and($budget->consume(1))->toBeFalse()
        ->and($budget->part)->toBe(61)
        ->and($budget->used)->toBe(151);
});

test('the_byte_budget_trips_on_the_actual_ratio_only_above_the_minimum_size', function (): void {
    $budget = new ByteBudget(perPart: PHP_INT_MAX, total: PHP_INT_MAX, ratio: 10, ratioMinSize: 1000);
    $budget->startPart(10);

    // 1,000 bytes out of 10 is a ratio of 100, but still within the minimum size.
    expect($budget->consume(1000))->toBeTrue()
        ->and($budget->consume(1))->toBeFalse();

    $honest = new ByteBudget(perPart: PHP_INT_MAX, total: PHP_INT_MAX, ratio: 10, ratioMinSize: 1000);
    $honest->startPart(500);
    expect($honest->consume(5000))->toBeTrue();

    $noRatio = new ByteBudget(perPart: PHP_INT_MAX, total: PHP_INT_MAX);
    $noRatio->startPart(1);
    expect($noRatio->consume(10_000_000))->toBeTrue();
});
