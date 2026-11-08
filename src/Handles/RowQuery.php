<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Handles;

use Generator;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\DataTransferObjects\CellError;
use RoundlyConsulting\Imports\DataTransferObjects\RecordReference;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\DataTransferObjects\RowResult;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\RowOutcome;
use RoundlyConsulting\Imports\Enums\RowStatus;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Pipeline\InFileKeys;
use RoundlyConsulting\Imports\Pipeline\RowPipeline;
use RoundlyConsulting\Imports\Reading\Cell;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\ImportModels;
use RoundlyConsulting\Imports\Support\SessionRows;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Templates\TemplateRows;
use RoundlyConsulting\Imports\Xlsx\CellReference;

/**
 * The preview's rows, filtered: stored exceptions come from the row-result table; plain creates
 * (never stored) are read from the file. Updates carry the field diff, computed per page by
 * re-reading those rows — row values are never stored.
 */
final readonly class RowQuery
{
    public function __construct(
        private ImportsManager $manager,
        private ImportSession $session,
        private RowFilter $filter,
    ) {}

    /**
     * @return LengthAwarePaginator<int, RowResult>
     */
    public function paginate(int $perPage = 50, ?int $page = null): LengthAwarePaginator
    {
        $perPage = max(1, min(500, $perPage));
        $page = max(1, $page ?? Paginator::resolveCurrentPage());

        if ($this->filter->listsCreates() && $this->session->kind === ImportKind::Rows) {
            $items = [];
            $skip = ($page - 1) * $perPage;

            foreach ($this->creates() as $result) {
                if ($skip-- > 0) {
                    continue;
                }

                $items[] = $result;

                if (count($items) >= $perPage) {
                    break;
                }
            }

            return new LengthAwarePaginator($items, $this->session->count('creates'), $perPage, $page);
        }

        if ($this->filter->field !== null) {
            $matching = iterator_to_array($this->cursor(), false);

            return new LengthAwarePaginator(array_slice($matching, ($page - 1) * $perPage, $perPage), count($matching), $perPage, $page);
        }

        $paginator = $this->query()->orderBy('row_number')->paginate($perPage, ['*'], 'page', $page);
        $results = array_values(array_map($this->result(...), $paginator->items()));

        if ($this->filter->outcome === RowOutcome::Update) {
            $results = $this->withChanges($results);
        }

        return new LengthAwarePaginator($results, $paginator->total(), $perPage, $page);
    }

    /**
     * Every matching row, streamed.
     *
     * @return Generator<int, RowResult>
     */
    public function cursor(): Generator
    {
        if ($this->filter->listsCreates() && $this->session->kind === ImportKind::Rows) {
            yield from $this->creates();

            return;
        }

        foreach ($this->query()->orderBy('row_number')->cursor() as $row) {
            if ($this->filter->field === null || in_array($this->filter->field, array_column($row->errors ?? [], 'field'), true)) {
                yield $this->result($row);
            }
        }
    }

    public function count(): int
    {
        return match (true) {
            $this->filter->listsCreates() && $this->session->kind === ImportKind::Rows => $this->session->count('creates'),
            $this->filter->field !== null => count(iterator_to_array($this->cursor(), false)),
            default => $this->query()->count(),
        };
    }

    /**
     * @return Builder<ImportRowResult>
     */
    private function query(): Builder
    {
        $query = ImportModels::rowResult()::query()->where('session_id', $this->session->getKey());

        if ($this->filter->statuses !== []) {
            $query->whereIn('status', array_map(static fn (RowStatus $s): string => $s->value, $this->filter->statuses));
        }

        if ($this->filter->outcome !== null) {
            $query->where('outcome', $this->filter->outcome->value);
        }

        return $query;
    }

    private function result(ImportRowResult $row): RowResult
    {
        $errors = [];

        foreach ($row->errors ?? [] as $error) {
            $errors[] = new CellError($error['field'], $error['column'], $error['column'] === null ? null : CellReference::letters($error['column']), $error['code'], $error['message'], $error['value']);
        }

        $match = null;

        if ($row->matched_record_id !== null) {
            $definition = $this->manager->container()->make(ImporterRegistry::class)->find($this->session->importer);

            if ($definition instanceof Importer) {
                $model = $definition->model();
                $match = new RecordReference((new $model)->getMorphClass(), $row->matched_record_id);
            }
        }

        return new RowResult($row->row_number, $row->status, $row->outcome, $errors, $match, [], $row->label);
    }

    /**
     * The rows a commit would create: every data row not listed as an exception, plus matched rows
     * planned as creates.
     *
     * @return Generator<int, RowResult>
     */
    private function creates(): Generator
    {
        $importer = $this->manager->container()->make(ImporterRegistry::class)->rows($this->session->importer);
        $mapping = SessionState::mapping($this->session)->toArray();
        $temp = TempFiles::directory();
        $offsets = $this->session->chunk_offsets ?? [];
        [$offset, $first] = $offsets[0] ?? [0, $this->session->header_row + 1];

        try {
            $chunk = [];
            $last = 0;

            foreach (SessionRows::from($this->session, (int) $offset, (int) $first, $temp, $importer->limits()->columns) as $number => $cells) {
                $chunk[$number] = $cells;
                $last = $number;

                if (count($chunk) >= 500) {
                    yield from $this->createsIn($importer, $mapping, $chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== [] || $last === 0) {
                yield from $this->createsIn($importer, $mapping, $chunk);
            }
        } finally {
            TempFiles::remove($temp);
        }
    }

    /**
     * @param  array<int, string>  $mapping
     * @param  array<int, list<Cell|null>>  $rows
     * @return Generator<int, RowResult>
     */
    private function createsIn(Importer $importer, array $mapping, array $rows): Generator
    {
        if ($rows === []) {
            return;
        }

        $stored = [];

        foreach (ImportModels::rowResult()::query()->where('session_id', $this->session->getKey())->whereBetween('row_number', [min(array_keys($rows)), max(array_keys($rows))])->get() as $result) {
            $stored[$result->row_number] = $result;
        }

        foreach ($rows as $number => $cells) {
            $result = $stored[$number] ?? null;

            if ($result !== null) {
                if ($result->outcome === RowOutcome::Create) {
                    yield $this->result($result);
                }

                continue;
            }

            $byField = [];

            foreach ($mapping as $index => $field) {
                $byField[$field] = $cells[$index] ?? null;
            }

            if (! TemplateRows::isTemplateRow($importer, $byField)) {
                yield new RowResult($number, RowStatus::Valid, RowOutcome::Create);
            }
        }
    }

    /**
     * @param  list<RowResult>  $results
     * @return list<RowResult>
     */
    private function withChanges(array $results): array
    {
        if ($results === []) {
            return [];
        }

        $container = $this->manager->container();
        $importer = $container->make(ImporterRegistry::class)->rows($this->session->importer);
        $owner = Audits::owner($this->session);
        $temp = TempFiles::directory();

        try {
            $numbers = array_fill_keys(array_map(static fn (RowResult $r): int => $r->rowNumber, $results), true);
            $rows = iterator_to_array(SessionRows::pick($this->session, $numbers, $temp, $importer->limits()->columns), true);
            $pipeline = new RowPipeline($importer, SessionState::mapping($this->session)->toArray(), count($this->session->headers), $owner, $container->make(OwnerScope::class), $this->session->locale, $this->session->timezone, $container->make(ValidationFactory::class));
            $changes = [];

            foreach ($pipeline->evaluate($rows, new InFileKeys) as $evaluation) {
                if ($evaluation->row !== null && $evaluation->match !== null) {
                    $changes[$evaluation->number] = $importer->diff($evaluation->match, $evaluation->row);
                }
            }
        } finally {
            TempFiles::remove($temp);
        }

        return array_map(
            static fn (RowResult $r): RowResult => new RowResult($r->rowNumber, $r->status, $r->outcome, $r->errors, $r->match, $changes[$r->rowNumber] ?? [], $r->label),
            $results,
        );
    }
}
