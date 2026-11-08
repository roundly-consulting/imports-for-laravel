<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Imports\Enums\ImportKind;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Support\Locales;

/**
 * What row importers and file importers share: a key, a label, an owner rule, an authorization
 * callback and the undo hooks. Extend `Importer` or `FileImporter`, never this.
 */
abstract class ImportDefinition
{
    /**
     * `^[a-z][a-z0-9_.-]{0,63}$`, unique in the registry. Default: the class name without
     * `Importer`, in snake case (`AssetsImporter` → `assets`, `TeamMembersImporter` →
     * `team_members`). Saved mappings and `HasImportSource` lookups are keyed by it, so renaming
     * the class renames the key: production importers should declare it.
     */
    public function key(): string
    {
        return Str::of(class_basename(static::class))->beforeLast('Importer')->snake()->lower()->toString();
    }

    /**
     * A translation key (or text), or a locale map `['en' => …, 'sk' => …]`. Default: the key as a
     * headline (`team_members` → `Team Members`); override it to translate the label.
     *
     * @return string|array<string, string>
     */
    public function label(): string|array
    {
        return Str::headline($this->key());
    }

    /**
     * How the importer's records belong to the import's owner. Required: fail closed.
     */
    abstract public function ownership(): Ownership;

    /**
     * Whether `$user` may perform `$operation` for `$owner`. Required: fail closed.
     */
    abstract public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool;

    abstract public function kind(): ImportKind;

    /**
     * Undo one created, unchanged record. Return Deleted (the default deletes it), Handled (you
     * undid it another way, e.g. revoked an invitation) or Kept (leave it, e.g. already accepted).
     */
    public function rollback(Model $record, ImportContext $context): RollbackOutcome
    {
        $record->delete();

        return RollbackOutcome::Deleted;
    }

    public function changeDetection(): ChangeDetection
    {
        return ChangeDetection::checksum();
    }

    /**
     * The attributes undo compares to decide whether a created record changed since the import.
     * Default: every raw attribute but the key and the timestamps.
     *
     * @return array<string, mixed>
     */
    public function checksumAttributes(Model $record): array
    {
        $attributes = $record->getRawOriginal();
        $only = $this->changeDetection()->attributes;

        if ($only !== null) {
            return array_intersect_key($attributes, array_flip($only));
        }

        unset($attributes[$record->getKeyName()]);

        foreach ([$record->getCreatedAtColumn(), $record->getUpdatedAtColumn(), 'deleted_at'] as $column) {
            if ($column !== null) {
                unset($attributes[$column]);
            }
        }

        return $attributes;
    }

    public function labelIn(?string $locale = null): string
    {
        return Locales::text($this->label(), $locale) ?? $this->key();
    }
}
