<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\FileKey;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use Workbench\App\Models\Invitation;

/**
 * One invitation per email, always created: old invitations never count as matches, but the
 * same email twice in one file is a duplicate (`uniqueInFile()`). The switches are reset before
 * every test.
 */
final class InvitationsImporter extends Importer
{
    public static ?FileKey $fileKey = null;

    public static ?NaturalKey $naturalKey = null;

    public static ?string $labelField = null;

    public static function reset(): void
    {
        self::$fileKey = FileKey::by('email')->caseInsensitive();
        self::$naturalKey = null;
        self::$labelField = null;
    }

    public function key(): string
    {
        return 'invitations';
    }

    public function model(): string
    {
        return Invitation::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function naturalKey(): ?NaturalKey
    {
        return self::$naturalKey;
    }

    public function uniqueInFile(): ?FileKey
    {
        return self::$fileKey;
    }

    public function labelField(): ?string
    {
        return self::$labelField;
    }

    public function fields(): array
    {
        return [
            Field::email('email')->label('Email'),
            Field::text('first_name')->label('First name'),
            Field::text('role')->label('Role')->default('member'),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return true;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return Invitation::query()->create([
            'team_id' => $context->owner->getKey(),
            'email' => $row->get('email'),
            'first_name' => $row->get('first_name'),
            'role' => $row->get('role') ?? 'member',
        ]);
    }
}
