<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Pipeline;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Rows\ImportRow;

/**
 * What the pipeline concluded about one data row.
 *
 * @internal
 */
final class RowEvaluation
{
    public RowStatus $status = RowStatus::Valid;

    /** @var list<array{field: string|null, column: int|null, code: string, message: string, value: string|null}> */
    public array $errors = [];

    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, Model|null> */
    public array $references = [];

    public ?Model $match = null;

    public ?string $matchKey = null;

    public ?int $matchCandidate = null;

    public ?string $duplicateReason = null;

    public bool $templateRow = false;

    public ?ImportRow $row = null;

    /**
     * @param  array<string, Cell|null>  $cells  mapped field => cell
     * @param  list<Cell|null>  $raw  the row as read
     */
    public function __construct(
        public readonly int $number,
        public readonly array $cells,
        public readonly array $raw,
    ) {}

    public function fail(?string $field, ?int $column, string $code, string $message, ?string $value): void
    {
        $this->status = RowStatus::Invalid;
        $this->errors[] = [
            'field' => $field,
            'column' => $column,
            'code' => $code,
            'message' => $message,
            'value' => $value === null ? null : mb_substr($value, 0, 100),
        ];
    }

    public function isValid(): bool
    {
        return $this->status === RowStatus::Valid || $this->status === RowStatus::Matched;
    }
}
