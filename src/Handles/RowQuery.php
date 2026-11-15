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
use RoundlyConsulting\Imports\Exceptions\ImportStateException;
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
use RoundlyConsulting\Imports\Support\RowLabels;
use RoundlyConsulting\Imports\Support\SessionRows;
use RoundlyConsulting\Imports\Support\SessionState;
use RoundlyConsulting\Imports\Support\TempFiles;
use RoundlyConsulting\Imports\Templates\TemplateRows;

/**
 * The preview's rows, filtered, each with a label. Stored rows (flagged ones, and every entry of a
 * file batch) come from the row-result table; a row import's plain creates are never stored, so a
 * filter that lists them streams the file and merges the stored rows in. Labels and an update's
 * field diff are read from the file per page — row values are never stored.
 */
final readonly class RowQuery
{
    private const int BLOCK = 200;

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
        $skip = ($page - 1) * $perPage;

        if ($this->streams()) {
            if ($this->filter->everyRow) {
                return new LengthAwarePaginator(self::take($this->every($skip), $perPage), $this->session->countOf('total'), $perPage, $page);
            }

            if ($this->filter->listsCreates() && $this->filter->statuses === [] && $this->filter->field === null) {
                return new LengthAwarePaginator(self::take($this->streamed(), $perPage, $skip), $this->session->countOf('creates'), $perPage, $page);
            }

            $matching = iterator_to_array($this->streamed(), false);

            return new LengthAwarePaginator(array_slice($matching, $skip, $perPage), count($matching), $perPage, $page);
        }

        if ($this->filter->field !== null) {
            $matching = iterator_to_array($this->stored(), false);

            return new LengthAwarePaginator($this->labelled(array_slice($matching, $skip, $perPage)), count($matching), $perPage, $page);
        }

        $paginator = $this->query()->orderBy('row_number')->paginate($perPage, ['*'], 'page', $page);
        $results = $this->labelled(array_values($paginator->items()));

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
        if ($this->streams()) {
            yield from $this->streamed();

            return;
        }

        $block = [];

        foreach ($this->stored() as $row) {
            $block[] = $row;

            if (count($block) >= self::BLOCK) {
                yield from $this->labelled($block);
                $block = [];
            }
        }

        yield from $this->labelled($block);
    }

    public function count(): int
    {
        return match (true) {
            $this->streams() && $this->filter->everyRow => $this->session->countOf('total'),
            $this->streams() && $this->filter->listsCreates() && $this->filter->statuses === [] && $this->filter->field === null => $this->session->countOf('creates'),
            $this->streams() => iterator_count($this->streamed()),
            $this->filter->field !== null => iterator_count($this->stored()),
            default => $this->query()->count(),
        };
    }

    /**
     * Whether the list holds plain creates, read from the file. `creates()` always does (and
     * refuses a session without a preview, as before); `all()` and `status(Valid)` do once the
     * preview stored its chunk offsets and while the file is kept, else they list stored rows.
     */
    private function streams(): bool
    {
        if ($this->session->kind !== ImportKind::Rows || ! $this->filter->listsValidRows()) {
            return false;
        }

        if ($this->filter->listsCreates() && ! $this->filter->everyRow) {
            return true;
        }

        return $this->readable();
    }

    private function readable(): bool
    {
        return $this->session->chunk_offsets !== null && $this->session->file_pruned_at === null;
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

    /**
     * The stored rows the filter matches, in row order.
     *
     * @return Generator<int, ImportRowResult>
     */
    private function stored(): Generator
    {
        foreach ($this->query()->orderBy('row_number')->cursor() as $row) {
            if ($this->filter->field === null || in_array($this->filter->field, array_column($row->errors ?? [], 'field'), true)) {
                yield $row;
            }
        }
    }

    private function result(ImportRowResult $row, ?string $label = null): RowResult
    {
        $errors = [];

        foreach ($row->errors ?? [] as $error) {
            $errors[] = CellError::fromStored($error);
        }

        $match = null;

        if ($row->matched_record_id !== null) {
            $definition = $this->manager->container()->make(ImporterRegistry::class)->find($this->session->importer);

            if ($definition instanceof Importer) {
                $model = $definition->model();
                $match = new RecordReference((new $model)->getMorphClass(), $row->matched_record_id);
            }
        }

        return new RowResult($row->row_number, $row->status, $row->outcome, $errors, $match, [], $row->label ?? $label ?? RowLabels::fallback($row->row_number, $this->session->locale));
    }

    /**
     * Stored rows as results, labelled from their cells in one pass over the file (a file batch's
     * entries carry their file name). Without the file, a row is labelled "Row N".
     *
     * @param  list<ImportRowResult>  $rows
     * @return list<RowResult>
     */
    private function labelled(array $rows): array
    {
        $labels = [];

        if ($rows !== [] && $this->session->kind === ImportKind::Rows && $this->readable()) {
            $importer = $this->manager->container()->make(ImporterRegistry::class)->rows($this->session->importer);
            $labeler = RowLabels::for($importer, SessionState::mapping($this->session)->toArray());
            $candidates = [];

            foreach ($rows as $row) {
                $candidates[$row->row_number] = $row->match_candidate;
            }

            $temp = TempFiles::directory();

            try {
                foreach (SessionRows::pick($this->session, array_fill_keys(array_keys($candidates), true), $temp, $importer->limits()->columns) as $number => $cells) {
                    $labels[$number] = $labeler->of($cells, $candidates[$number] ?? null);
                }
            } catch (ImportStateException) {
                // The file went missing without being marked pruned: the rows keep "Row N".
            } finally {
                TempFiles::remove($temp);
            }
        }

        return array_map(fn (ImportRowResult $row): RowResult => $this->result($row, $labels[$row->row_number] ?? null), $rows);
    }

    /**
     * The filter's rows of a row import in file order: plain creates read from the file, stored
     * rows merged in.
     *
     * @return Generator<int, RowResult>
     */
    private function streamed(): Generator
    {
        foreach ($this->every() as $result) {
            if ($this->accepts($result)) {
                yield $result;
            }
        }
    }

    private function accepts(RowResult $result): bool
    {
        if ($this->filter->statuses !== [] && ! in_array($result->status, $this->filter->statuses, true)) {
            return false;
        }

        if ($this->filter->outcome !== null && $result->outcome !== $this->filter->outcome) {
            return false;
        }

        return $this->filter->field === null
            || in_array($this->filter->field, array_map(static fn (CellError $error): ?string => $error->field, $result->errors), true);
    }

    /**
     * Every data row of a row import, labelled, from the `$skip`-th on (template rows are not data
     * rows). A deep page starts at the commit chunk that holds it — the preview stored each chunk's
     * offset — instead of the file's top.
     *
     * @return Generator<int, RowResult>
     */
    private function every(int $skip = 0): Generator
    {
        // No stored offset = the preview found no data rows; reading the file from its top would
        // list the header (and any preamble) as rows. A session never previewed has null offsets
        // and SessionRows refuses it as stale.
        if ($this->session->chunk_offsets === []) {
            return;
        }

        $offsets = $this->session->chunk_offsets ?? [];
        $size = max(1, $this->session->chunk_size ?? 1);
        $chunk = 0;

        if ($skip > 0) {
            // Template rows can only be the first WINDOW rows read: past them, a data row's position
            // among the rows read is its position among data rows plus the template rows.
            $read = $skip + $this->session->countOf('template_rows');
            $target = intdiv($read, $size);

            if ($target > 0 && $target * $size >= TemplateRows::WINDOW) {
                if (! isset($offsets[$target])) {
                    return;
                }

                $chunk = $target;
                $skip = $read - $target * $size;
            }
        }

        [$offset, $first] = $offsets[$chunk] ?? [0, 1];

        foreach ($this->read((int) $offset, (int) $first, $chunk * $size) as $result) {
            if ($skip > 0) {
                $skip--;

                continue;
            }

            yield $result;
        }
    }

    /**
     * The data rows from a chunk's offset, block by block, merged with their stored results.
     *
     * @param  int  $position  the first row's position among the rows read (template rows count)
     * @return Generator<int, RowResult>
     */
    private function read(int $offset, int $first, int $position): Generator
    {
        $importer = $this->manager->container()->make(ImporterRegistry::class)->rows($this->session->importer);
        $mapping = SessionState::mapping($this->session)->toArray();
        $labeler = RowLabels::for($importer, $mapping);
        $fields = [];

        foreach ($importer->fields() as $field) {
            $fields[$field->name] = $field;
        }

        $expectations = TemplateRows::expectations($fields);
        $temp = TempFiles::directory();

        try {
            $block = [];

            foreach (SessionRows::from($this->session, $offset, $first, $temp, $importer->limits()->columns) as $number => $cells) {
                $block[$number] = $cells;

                if (count($block) >= self::BLOCK) {
                    yield from $this->block($labeler, $expectations, $mapping, $block, $position);
                    $position += count($block);
                    $block = [];
                }
            }

            if ($block !== []) {
                yield from $this->block($labeler, $expectations, $mapping, $block, $position);
            }
        } finally {
            TempFiles::remove($temp);
        }
    }

    /**
     * @param  list<array<string, string>>  $expectations
     * @param  array<int, string>  $mapping
     * @param  non-empty-array<int, list<Cell|null>>  $rows
     * @return list<RowResult>
     */
    private function block(RowLabels $labeler, array $expectations, array $mapping, array $rows, int $firstPosition): array
    {
        $stored = [];

        foreach (ImportModels::rowResult()::query()->where('session_id', $this->session->getKey())->whereBetween('row_number', [min(array_keys($rows)), max(array_keys($rows))])->get() as $result) {
            $stored[$result->row_number] = $result;
        }

        $window = array_fill_keys(TemplateRows::window($rows, $firstPosition), true);
        $results = [];

        foreach ($rows as $number => $cells) {
            $result = $stored[$number] ?? null;

            if ($result !== null) {
                $results[] = $this->result($result, $labeler->of($cells, $result->match_candidate));

                continue;
            }

            $byField = [];

            foreach ($mapping as $index => $field) {
                $byField[$field] = $cells[$index] ?? null;
            }

            if (! isset($window[$number]) || ! TemplateRows::matches($expectations, $byField)) {
                $results[] = new RowResult($number, RowStatus::Valid, RowOutcome::Create, label: $labeler->of($cells) ?? RowLabels::fallback($number, $this->session->locale));
            }
        }

        return $results;
    }

    /**
     * The first `$count` results after skipping `$skip`.
     *
     * @param  Generator<int, RowResult>  $results
     * @return list<RowResult>
     */
    private static function take(Generator $results, int $count, int $skip = 0): array
    {
        $items = [];

        foreach ($results as $result) {
            if ($skip-- > 0) {
                continue;
            }

            $items[] = $result;

            if (count($items) >= $count) {
                break;
            }
        }

        return $items;
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
            $scope = $container->make(OwnerScope::class);
            $pipeline = new RowPipeline($importer, SessionState::mapping($this->session)->toArray(), count($this->session->headers), $owner, $scope, $this->session->locale, $this->session->timezone, $container->make(ValidationFactory::class), $this->session->date1904);

            // References, natural-key matches and diff() read host models: only inside the owner.
            $changes = $scope->run($owner, static function () use ($pipeline, $rows, $importer): array {
                $changes = [];

                foreach ($pipeline->evaluate($rows, new InFileKeys) as $evaluation) {
                    if ($evaluation->row !== null && $evaluation->match !== null) {
                        $changes[$evaluation->number] = $importer->diff($evaluation->match, $evaluation->row);
                    }
                }

                return $changes;
            });
        } finally {
            TempFiles::remove($temp);
        }

        return array_map(
            static fn (RowResult $r): RowResult => new RowResult($r->rowNumber, $r->status, $r->outcome, $r->errors, $r->match, $changes[$r->rowNumber] ?? [], $r->label),
            $results,
        );
    }
}
