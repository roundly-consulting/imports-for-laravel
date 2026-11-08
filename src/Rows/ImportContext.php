<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Rows;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Contracts\OwnerScope;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\DuplicateMode;
use RoundlyConsulting\Imports\Pipeline\Scoped;

/**
 * What an importer callback knows about the import it runs in: the owner, the actor, the session
 * and batch, the mode, the locale and timezone — and an owner-scoped query helper.
 */
final readonly class ImportContext
{
    public function __construct(
        public Model $owner,
        public ?Model $actor,
        public ImportDefinition $importer,
        public int|string $sessionId,
        public int|string|null $batchId,
        public DuplicateMode $mode,
        public string $locale,
        public string $timezone,
        private OwnerScope $scope,
    ) {}

    /**
     * A query on a host model, constrained to the owner (by the importer's rule unless another
     * is given).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public function query(string $model, ?Ownership $rule = null): Builder
    {
        return Scoped::query($model, $this->owner, $rule ?? $this->importer->ownership(), $this->scope);
    }

    /**
     * The attributes that put a new record inside the owner (`['team_id' => 7]` for
     * `Ownership::column('team_id')`).
     *
     * @return array<string, mixed>
     */
    public function ownerAttributes(): array
    {
        return $this->importer->ownership()->attributes($this->owner);
    }

    /**
     * @internal
     */
    public function scope(): OwnerScope
    {
        return $this->scope;
    }
}
