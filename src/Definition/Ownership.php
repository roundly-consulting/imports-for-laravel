<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Definition;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How a host model belongs to an import's owner — declared, never guessed (fail closed):
 *
 * ```php
 * Ownership::column('team_id');                 // where team_id = owner key
 * Ownership::belongsTo('team');                 // whereBelongsTo($owner, 'team')
 * Ownership::morph('owner');                    // owner_type + owner_id
 * Ownership::using(query: fn (Builder $q, Model $owner) => …, owns: fn (Model $record, Model $owner) => …);
 * Ownership::global();                          // the explicit opt-out: no constraint
 * ```
 *
 * A reference field inherits its importer's rule by default (`Ownership::inherit()`).
 */
final readonly class Ownership
{
    /**
     * @param  'column'|'belongsTo'|'morph'|'using'|'global'|'inherit'  $kind
     * @param  Closure|null  $query  (Builder $query, Model $owner): mixed
     * @param  (Closure(Model, Model): bool)|null  $owns
     */
    private function __construct(
        public string $kind,
        public ?string $name = null,
        public ?Closure $query = null,
        public ?Closure $owns = null,
    ) {}

    public static function column(string $column): self
    {
        return new self('column', $column);
    }

    public static function belongsTo(string $relation): self
    {
        return new self('belongsTo', $relation);
    }

    public static function morph(string $name): self
    {
        return new self('morph', $name);
    }

    /**
     * @param  Closure  $query  (Builder $query, Model $owner): mixed — constrain a query to the owner
     * @param  Closure(Model, Model): bool  $owns  whether a record is inside the owner
     */
    public static function using(Closure $query, Closure $owns): self
    {
        return new self('using', null, $query, $owns);
    }

    public static function global(): self
    {
        return new self('global');
    }

    public static function inherit(): self
    {
        return new self('inherit');
    }

    public function isGlobal(): bool
    {
        return $this->kind === 'global';
    }

    /**
     * The attributes that put a new record inside the owner (e.g. `['team_id' => 7]`), when the
     * rule is a plain column or morph.
     *
     * @return array<string, mixed>
     */
    public function attributes(Model $owner): array
    {
        return match ($this->kind) {
            'column' => [(string) $this->name => $owner->getKey()],
            'morph' => [$this->name.'_type' => $owner->getMorphClass(), $this->name.'_id' => $owner->getKey()],
            default => [],
        };
    }

    public function describe(): string
    {
        return $this->name === null ? $this->kind : $this->kind.'('.$this->name.')';
    }
}
