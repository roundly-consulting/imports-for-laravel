<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Reading;

use Generator;

/**
 * A streaming source of spreadsheet rows: every non-blank row from the top, keyed by the row
 * number a user sees (1-based), as a list of cells (`null` = empty).
 *
 * @internal
 */
interface RowSource
{
    /**
     * @return Generator<int, list<Cell|null>>
     */
    public function rows(): Generator;

    /**
     * The byte offset the row last yielded starts at, where the source can seek back to it.
     */
    public function lastOffset(): ?int;

    public function close(): void;
}
