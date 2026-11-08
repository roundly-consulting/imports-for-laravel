<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Imports\DataTransferObjects\MappingSuggestion;
use RoundlyConsulting\Imports\DataTransferObjects\SuggestedColumn;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\RejectionReason;
use RoundlyConsulting\Imports\Exceptions\ImportFileRejectedException;
use RoundlyConsulting\Imports\Handles\SessionHandle;
use RoundlyConsulting\Imports\Http\Requests\MapRequest;
use RoundlyConsulting\Imports\Http\Requests\SaveMappingRequest;
use RoundlyConsulting\Imports\Http\Requests\SourceRequest;
use RoundlyConsulting\Imports\Http\Requests\UploadFilesRequest;
use RoundlyConsulting\Imports\Http\Requests\UploadRequest;
use RoundlyConsulting\Imports\Http\Resources\ImportSessionResource;

/**
 * @internal
 */
final class SessionsController extends Controller
{
    public function store(UploadRequest $request, string $importer): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ImportFileRejectedException::because(RejectionReason::Unreadable);
        }

        $sheet = $request->validated('sheet_index') ?? $request->validated('sheet');
        $delimiter = $request->validated('delimiter');
        $headerRow = $request->validated('header_row');
        $locale = $request->validated('locale');
        $mode = $request->validated('mode');

        $session = $this->scoped($request)->upload(
            $importer,
            $file,
            is_int($sheet) || is_string($sheet) ? (is_numeric($sheet) && ! is_string($request->validated('sheet')) ? (int) $sheet : $sheet) : null,
            is_string($delimiter) ? ($delimiter === 'tab' ? "\t" : $delimiter) : null,
            is_numeric($headerRow) ? (int) $headerRow : 1,
            is_string($locale) ? $locale : null,
        );

        if (is_string($mode)) {
            $session->mode(DuplicateMode::from($mode));
        }

        return self::session($session, 201);
    }

    public function storeFiles(UploadFilesRequest $request, string $importer): JsonResponse
    {
        $files = array_values(array_filter((array) $request->file('files'), static fn (mixed $f): bool => $f instanceof UploadedFile));
        $locale = $request->validated('locale');

        return self::session($this->scoped($request)->uploadFiles($importer, $files, is_string($locale) ? $locale : null), 201);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return self::session($this->scoped($request)->session($session));
    }

    public function source(SourceRequest $request, string $session): JsonResponse
    {
        $handle = $this->scoped($request)->session($session);
        $sheet = $request->validated('sheet_index') ?? $request->validated('sheet');
        $delimiter = $request->validated('delimiter');
        $headerRow = $request->validated('header_row');

        if (is_int($sheet) || is_string($sheet)) {
            $handle->useSheet(is_numeric($sheet) && ! is_string($request->validated('sheet')) ? (int) $sheet : $sheet);
        }

        if (is_string($delimiter)) {
            $handle->useDelimiter($delimiter === 'tab' ? "\t" : $delimiter);
        }

        if (is_numeric($headerRow)) {
            $handle->useHeaderRow((int) $headerRow);
        }

        return self::session($handle);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $this->scoped($request)->session($session)->discard();

        return new JsonResponse(null, 204);
    }

    public function suggestion(Request $request, string $session): JsonResponse
    {
        return new JsonResponse(['data' => self::suggestionArray($this->scoped($request)->session($session)->suggestMapping())]);
    }

    public function map(MapRequest $request, string $session): JsonResponse
    {
        $mapping = [];

        foreach ((array) $request->validated('mapping') as $column => $field) {
            $mapping[is_numeric($column) ? (int) $column : (string) $column] = is_string($field) ? $field : null;
        }

        return self::session($this->scoped($request)->session($session)->map($mapping));
    }

    public function saveMapping(SaveMappingRequest $request, string $session): JsonResponse
    {
        $name = $request->validated('name');

        return self::session($this->scoped($request)->session($session)->saveMapping(is_string($name) ? $name : ''));
    }

    private static function session(SessionHandle $handle, int $status = 200): JsonResponse
    {
        $model = $handle->model();
        $model->loadMissing(['owner', 'actor', 'batch']);

        return (new ImportSessionResource($model))->response()->setStatusCode($status);
    }

    /**
     * @return array<string, mixed>
     */
    private static function suggestionArray(MappingSuggestion $suggestion): array
    {
        return [
            'columns' => array_map(static fn (SuggestedColumn $c): array => [
                'index' => $c->index,
                'header' => $c->header,
                'field' => $c->field,
                'confidence' => $c->confidence,
                'source' => $c->source?->value,
                'fuzzy' => $c->isFuzzy(),
            ], $suggestion->columns),
            'unmapped_required' => $suggestion->unmappedRequired,
            'ignored_columns' => $suggestion->ignoredColumns,
            'saved_mapping' => $suggestion->savedMapping,
            'complete' => $suggestion->isComplete(),
        ];
    }
}
