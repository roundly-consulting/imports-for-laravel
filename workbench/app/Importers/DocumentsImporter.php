<?php

declare(strict_types=1);

namespace Workbench\App\Importers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\FileDuplicates;
use RoundlyConsulting\Imports\Definition\FileRules;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\FileImporter;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportFile;
use Workbench\App\Models\Document;
use Workbench\App\Models\User;

/**
 * A file batch: each uploaded PDF, DOCX or text file becomes a document.
 */
final class DocumentsImporter extends FileImporter
{
    /** A test switch for the duplicate check. */
    public static ?FileDuplicates $duplicates = null;

    public function duplicateCheck(): FileDuplicates
    {
        return self::$duplicates ?? parent::duplicateCheck();
    }

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Documents';
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function accepts(): FileRules
    {
        return FileRules::extensions('pdf', 'docx', 'txt', 'zip')->maxSize(2048);
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return $user instanceof User && $user->is_admin && $user->team_id === $owner->getKey();
    }

    public function create(ImportFile $file, ImportContext $context): Model
    {
        return Document::create([
            'team_id' => $context->owner->getKey(),
            'name' => $file->name,
            'path' => $file->storeOn('documents', 'imported'),
            'size' => $file->size,
        ]);
    }
}
