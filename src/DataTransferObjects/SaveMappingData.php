<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Mapping\HeaderNormalizer;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Support\Audits;
use RoundlyConsulting\Imports\Support\Keys;

/**
 * A mapping to save under a name for an owner: normalised header => field, plus the header
 * signature a file is recognised by. Built from a session (its headers and mapping) or from header
 * text given by host code — both end in the same stored shape.
 */
final readonly class SaveMappingData
{
    /**
     * @param  array<string, string>  $columns  normalised header => field
     */
    public function __construct(
        public Model $owner,
        public string $importer,
        public string $name,
        public array $columns,
        public string $signature,
        public ?Model $actor = null,
        public ?string $delimiter = null,
        public ?string $sheet = null,
        public int $headerRow = 1,
        public int|string|null $sessionId = null,
    ) {}

    public static function fromSession(ImportSession $session, string $name, ?Model $actor = null): self
    {
        $headers = $session->headers;
        $normalized = HeaderNormalizer::all($headers);
        $columns = [];

        foreach ($session->mapping ?? [] as $index => $field) {
            $header = $normalized[(int) $index] ?? '';

            if ($header !== '') {
                $columns[$header] = $field;
            }
        }

        return new self(
            owner: Audits::owner($session),
            importer: $session->importer,
            name: trim($name),
            columns: $columns,
            signature: HeaderNormalizer::signature($headers),
            actor: $actor,
            delimiter: $session->delimiter,
            sheet: $session->sheet,
            headerRow: $session->header_row,
            sessionId: Keys::of($session),
        );
    }

    /**
     * @param  array<string, string>  $columns  header text => field, in the order of the file's header row
     */
    public static function fromColumns(Model $owner, string $importer, string $name, array $columns, ?Model $actor = null): self
    {
        $normalized = [];

        foreach ($columns as $header => $field) {
            $normalized[HeaderNormalizer::normalize((string) $header)] = $field;
        }

        return new self(
            owner: $owner,
            importer: $importer,
            name: trim($name),
            columns: $normalized,
            signature: HeaderNormalizer::signature(array_map('strval', array_keys($columns))),
            actor: $actor,
        );
    }
}
