<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\FileDuplicates;
use RoundlyConsulting\Imports\Definition\FileRules;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportFile;

/**
 * A file batch: many files or a ZIP, each becoming one record through `create()` — with SHA-256
 * duplicate detection and a hardened ZIP extraction.
 *
 * ```php
 * final class DocumentsImporter extends FileImporter
 * {
 *     public function key(): string { return 'documents'; }
 *     public function accepts(): FileRules { return FileRules::extensions('pdf', 'docx')->maxSize(20_480); }
 *     public function create(ImportFile $file, ImportContext $context): Model { … }
 * }
 * ```
 */
abstract class FileImporter extends ImportDefinition
{
    abstract public function accepts(): FileRules;

    abstract public function create(ImportFile $file, ImportContext $context): Model;

    public function duplicateCheck(): FileDuplicates
    {
        return FileDuplicates::ledger();
    }

    final public function kind(): ImportKind
    {
        return ImportKind::Files;
    }
}
