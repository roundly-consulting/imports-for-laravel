<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\TemplateFormat;
use RoundlyConsulting\Imports\Http\Requests\TemplateRequest;
use RoundlyConsulting\Imports\Http\Resources\ImporterResource;
use RoundlyConsulting\Imports\Http\Resources\SavedMappingResource;
use RoundlyConsulting\Imports\Support\Access;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @internal
 */
final class ImportersController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        return ImporterResource::collection($this->imports->importers()->available($user instanceof Authenticatable ? $user : null, $this->owner($request)));
    }

    public function template(TemplateRequest $request, string $importer): StreamedResponse
    {
        Access::check($this->imports->importers()->find($importer), self::actor($request), $this->owner($request), ImportOperation::View);
        $format = $request->validated('format');
        $locale = $request->validated('locale');

        return $this->imports->template(
            $importer,
            is_string($format) ? TemplateFormat::from($format) : null,
            is_string($locale) ? $locale : null,
        )->download();
    }

    public function mappings(Request $request, string $importer): AnonymousResourceCollection
    {
        return SavedMappingResource::collection($this->scoped($request)->mappings($importer)->all());
    }

    public function deleteMapping(Request $request, string $importer, string $name): JsonResponse
    {
        $deleted = $this->scoped($request)->mappings($importer)->delete($name);

        return new JsonResponse(['deleted' => $deleted], $deleted ? 200 : 404);
    }
}
