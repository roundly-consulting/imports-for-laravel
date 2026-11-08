<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\DataTransferObjects;

/**
 * What undo did: records deleted, handled by the importer's hook (e.g. invitations revoked), kept
 * (changed after the import, kept by the hook, or linked — never created by this import), already
 * missing, and failed. `kept` lists every record left in place.
 */
final readonly class RollbackReport
{
    /**
     * @param  list<ImportedRecordView>  $kept
     */
    public function __construct(
        public int $deleted = 0,
        public int $handled = 0,
        public int $keptChanged = 0,
        public int $keptByHook = 0,
        public int $linked = 0,
        public int $missing = 0,
        public int $failed = 0,
        public array $kept = [],
    ) {}

    public function keptTotal(): int
    {
        return $this->keptChanged + $this->keptByHook + $this->linked;
    }
}
