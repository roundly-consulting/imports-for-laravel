<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What every import event carries, so a host can write its audit log: the session and batch, the
 * importer, the owner, the actor of that operation (uploader, committer or the one who undid it),
 * the file and the counts.
 */
final readonly class ImportAudit
{
    public function __construct(
        public int|string $sessionId,
        public int|string|null $batchId,
        public string $importer,
        public Model $owner,
        public ?Model $actor,
        public FileReference $file,
        public ImportCounts $counts,
        public CarbonImmutable $at,
    ) {}
}
