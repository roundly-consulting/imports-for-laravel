<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

/**
 * Bulk writes of row results (the preview can list thousands of exceptions).
 *
 * @internal
 */
final class RowResults
{
    /**
     * @param  list<array<string, mixed>>  $rows  attributes, `errors` as an array
     */
    public static function insert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $rowResult = ImportModels::rowResult();
        $model = new $rowResult;
        $now = Clock::format(Clock::now());
        $prepared = [];

        foreach ($rows as $row) {
            if ($model->usesUniqueIds()) {
                $row['id'] = $model->newUniqueId();
            }

            $row['errors'] = isset($row['errors']) ? json_encode($row['errors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $prepared[] = $row;
        }

        foreach (array_chunk($prepared, 200) as $chunk) {
            $rowResult::query()->insert(self::uniform($chunk));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function uniform(array $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $keys += array_fill_keys(array_keys($row), null);
        }

        return array_map(static fn (array $row): array => array_merge($keys, $row), $rows);
    }
}
