<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Handles;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Imports\Actions\Sessions\UploadFilesAction;
use RoundlyConsulting\Imports\Actions\Sessions\UploadImportAction;
use RoundlyConsulting\Imports\DataTransferObjects\ImportSource;
use RoundlyConsulting\Imports\DataTransferObjects\UploadData;
use RoundlyConsulting\Imports\DataTransferObjects\UploadFilesData;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use RoundlyConsulting\Imports\Exceptions\ImportNotFoundException;
use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;
use RoundlyConsulting\Imports\Support\Access;
use RoundlyConsulting\Imports\Support\ImportModels;

/**
 * Everything one owner (a team, a company, a user) imports. Scoping is a security boundary: a
 * session or batch of another owner is not found.
 */
final readonly class OwnerImports
{
    public function __construct(
        private ImportsManager $manager,
        private Model $owner,
        private ?Model $actor = null,
    ) {}

    /**
     * Act as `$actor` — the importer's authorize() is asked about this user for every operation.
     */
    public function by(Authenticatable|Model|null $actor): self
    {
        return new self($this->manager, $this->owner, $actor instanceof Model ? $actor : null);
    }

    public function upload(
        string $importer,
        ImportSource|UploadedFile|string $file,
        string|int|null $sheet = null,
        ?string $delimiter = null,
        int $headerRow = 1,
        ?string $locale = null,
    ): SessionHandle {
        $data = new UploadData($importer, $this->owner, $this->actor, ImportSource::from($file), $sheet, $delimiter, $headerRow, $locale);
        $session = $this->manager->perform('upload', UploadImportAction::class, static fn (UploadImportAction $action): ImportSession => $action->execute($data), ['importer' => $importer, 'owner' => $this->owner, 'data' => $data]);

        return new SessionHandle($this->manager, $session instanceof ImportSession ? $session : throw ImportNotFoundException::session(), $this->actor);
    }

    /**
     * @param  iterable<UploadedFile>|UploadedFile  $files  files and/or ZIP archives
     */
    public function uploadFiles(string $importer, iterable|UploadedFile $files, ?string $locale = null): SessionHandle
    {
        $list = [];

        foreach ($files instanceof UploadedFile ? [$files] : $files as $file) {
            $list[] = $file;
        }

        $data = new UploadFilesData($importer, $this->owner, $this->actor, $list, $locale);
        $session = $this->manager->perform('uploadFiles', UploadFilesAction::class, static fn (UploadFilesAction $action): ImportSession => $action->execute($data), ['importer' => $importer, 'owner' => $this->owner, 'data' => $data]);

        return new SessionHandle($this->manager, $session instanceof ImportSession ? $session : throw ImportNotFoundException::session(), $this->actor);
    }

    /**
     * The owner's sessions the actor may view: with `$importer`, that importer's (refused unless the
     * actor may view it); without, those of every importer whose authorize() allows `View`.
     *
     * @return Builder<ImportSession>
     */
    public function sessions(?string $importer = null, SessionStatus ...$status): Builder
    {
        $registry = $this->manager->container()->make(ImporterRegistry::class);
        $query = $this->scope();

        if ($importer !== null) {
            Access::check($registry->find($importer), $this->actor, $this->owner, ImportOperation::View);
            $query->where('importer', $importer);
        } else {
            $query->whereIn('importer', array_map(
                static fn (ImportDefinition $definition): string => $definition->key(),
                $registry->available($this->actor instanceof Authenticatable ? $this->actor : null, $this->owner),
            ));
        }

        if ($status !== []) {
            $query->whereIn('status', array_map(static fn (SessionStatus $s): string => $s->value, $status));
        }

        return $query;
    }

    public function session(int|string $id): SessionHandle
    {
        $session = $this->scope()->whereKey($id)->first() ?? throw ImportNotFoundException::session();
        $session->setRelation('owner', $this->owner);

        return new SessionHandle($this->manager, $session, $this->actor);
    }

    public function batch(int|string $id): BatchHandle
    {
        $batch = ImportModels::batch()::query()->whereKey($id)->whereIn('session_id', $this->scope()->withTrashed()->select('id'))->first()
            ?? throw ImportNotFoundException::batch();

        return new BatchHandle($this->manager, $batch, $this->actor);
    }

    public function mappings(string $importer): SavedMappingsHandle
    {
        return new SavedMappingsHandle($this->manager, $this->owner, $this->actor, $importer);
    }

    /**
     * The session of `$importer` currently committing or rolling back for this owner, if any.
     */
    public function running(string $importer): ?SessionHandle
    {
        $session = $this->sessions($importer, SessionStatus::Committing, SessionStatus::RollingBack)->first();

        return $session === null ? null : new SessionHandle($this->manager, $session, $this->actor);
    }

    /**
     * Every session of this owner — the scoping boundary, before any authorization.
     *
     * @return Builder<ImportSession>
     */
    private function scope(): Builder
    {
        return ImportModels::session()::query()
            ->where('owner_type', $this->owner->getMorphClass())
            ->where('owner_id', $this->owner->getKey())
            ->latest('id');
    }
}
