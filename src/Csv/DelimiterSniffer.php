<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Csv;

/**
 * Picks the delimiter whose parse of the first records is the most consistent: the most records
 * sharing the modal field count, then the larger modal count; a tie goes to the wider header row,
 * then to config order. When every candidate yields one column, the file is a single column.
 *
 * @internal
 */
final class DelimiterSniffer
{
    private const int RECORDS = 20;

    /**
     * @param  non-empty-list<string>  $candidates
     */
    public static function sniff(string $sample, array $candidates): string
    {
        $best = $candidates[0];
        $bestScore = [0, 0, 0];

        foreach ($candidates as $candidate) {
            $counts = self::fieldCounts($sample, $candidate);

            if ($counts === []) {
                continue;
            }

            $frequencies = array_count_values($counts);
            arsort($frequencies);
            $modal = (int) array_key_first($frequencies);

            if ($modal <= 1) {
                continue;
            }

            $score = [$frequencies[$modal], $modal, $counts[0]];

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return list<int>
     */
    private static function fieldCounts(string $sample, string $delimiter): array
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            return [];
        }

        fwrite($stream, $sample);
        rewind($stream);
        $counts = [];

        while (count($counts) < self::RECORDS && ($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($record === [null]) {
                continue;
            }

            $counts[] = count($record);
        }

        fclose($stream);

        return $counts;
    }
}
