<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Imports\DataTransferObjects\RowFilter;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Handles\BatchHandle;
use RoundlyConsulting\Imports\Http\Requests\ModeRequest;
use RoundlyConsulting\Imports\Http\Requests\RowsRequest;
use RoundlyConsulting\Imports\Http\Requests\TemplateRequest;
use RoundlyConsulting\Imports\Http\Resources\ImportBatchResource;
use RoundlyConsulting\Imports\Http\Resources\ImportPreviewResource;
use RoundlyConsulting\Imports\Http\Resources\ImportRowResultResource;
use RoundlyConsulting\Imports\Http\Resources\ImportSessionResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @internal
 */
final class PreviewController extends Controller
{
    public function mode(ModeRequest $request, string $session): JsonResponse
    {
        $mode = $request->validated('mode');
        $handle = $this->scoped($request)->session($session)->mode(DuplicateMode::from(is_string($mode) ? $mode : 'skip'));

        return (new ImportSessionResource($handle->model()))->response();
    }

    public function run(Request $request, string $session): JsonResponse
    {
        $handle = $this->scoped($request)->session($session)->preview();

        return (new ImportSessionResource($handle->model()))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        return (new ImportPreviewResource($this->scoped($request)->session($session)->previewData()))->response();
    }

    public function rows(RowsRequest $request, string $session): JsonResponse
    {
        $filter = match ($request->validated('status')) {
            'invalid' => RowFilter::invalid(),
            'duplicates' => RowFilter::duplicates(),
            'updates' => RowFilter::updates(),
            'creates' => RowFilter::creates(),
            'skips' => RowFilter::skips(),
            default => RowFilter::all(),
        };
        $perPage = $request->validated('per_page');
        $page = $request->validated('page');
        $rows = $this->scoped($request)->session($session)->rows($filter)->paginate(is_numeric($perPage) ? (int) $perPage : 50, is_numeric($page) ? (int) $page : 1);

        return ImportRowResultResource::collection($rows)->response();
    }

    public function errorReport(TemplateRequest $request, string $session): StreamedResponse
    {
        $format = $request->validated('format');

        return $this->scoped($request)->session($session)->errorReport(is_string($format) ? TemplateFormat::from($format) : TemplateFormat::Xlsx)->download();
    }

    public function commit(Request $request, string $session): JsonResponse
    {
        $batch = $this->scoped($request)->session($session)->commit();

        return self::batch($batch, 202);
    }

    private static function batch(BatchHandle $batch, int $status): JsonResponse
    {
        return (new ImportBatchResource($batch))->response()->setStatusCode($status);
    }
}
