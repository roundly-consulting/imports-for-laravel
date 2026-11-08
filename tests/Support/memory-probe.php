<?php

declare(strict_types=1);

/*
 * Reads every row of an XLSX in a fresh process and prints the peak memory the read added —
 * measured apart from the test process, whose own allocations would be noise.
 * Usage: php tests/Support/memory-probe.php <workbook.xlsx>
 */

use RoundlyConsulting\Imports\Definition\Limits;
use RoundlyConsulting\Imports\Reading\ReadOptions;
use RoundlyConsulting\Imports\Xlsx\XlsxReader;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$path = $argv[1] ?? '';
gc_collect_cycles();
$before = memory_get_peak_usage(true);

$reader = new XlsxReader($path, new ReadOptions(new Limits(1_048_576, 100_000, 100, 32767), sharedStringsMemory: 2_097_152));
$rows = 0;
$last = '';

foreach ($reader->rows() as $cells) {
    $rows++;
    $last = (string) ($cells[0]?->display() ?? '');
}

$reader->close();

echo json_encode(['rows' => $rows, 'last' => $last, 'delta' => memory_get_peak_usage(true) - $before]);
