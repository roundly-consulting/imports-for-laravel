<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Tests\Support\XlsxBuilder;

test('a_sheet_with_200k_shared_strings_stays_under_the_memory_budget', function (): void {
    $strings = [];
    $rows = '';

    for ($i = 0; $i < 200_000; $i++) {
        $strings[] = 'Unique shared string number '.$i.' with some padding text';
    }

    for ($row = 1; $row <= 20_000; $row++) {
        $cells = '';

        for ($column = 0; $column < 10; $column++) {
            $cells .= '<c r="'.chr(65 + $column).$row.'" t="s"><v>'.(($row - 1) * 10 + $column).'</v></c>';
        }

        $rows .= '<row r="'.$row.'">'.$cells.'</row>';
    }

    $path = XlsxBuilder::make(['Big' => $rows], sharedStrings: $strings);
    unset($strings, $rows);

    $output = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../Support/memory-probe.php').' '.escapeshellarg($path));
    $result = json_decode((string) $output, true);

    expect($result['rows'] ?? null)->toBe(20_000)
        ->and($result['last'] ?? null)->toBe('Unique shared string number 199990 with some padding text')
        ->and($result['delta'] ?? PHP_INT_MAX)->toBeLessThan(16 * 1024 * 1024);

    @unlink($path);
})->group('memory');
