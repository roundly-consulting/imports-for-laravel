<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Accessors;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\DataTransferObjects\ImporterDescriptor;
use RoundlyConsulting\Imports\Definition\ImportDefinition;
use RoundlyConsulting\Imports\Registry\ImporterRegistry;

/**
 * The app's importers, discoverable for a UI: `Imports::importers()->available($user, $team)`.
 */
final readonly class Importers
{
    public function __construct(private ImporterRegistry $registry) {}

    /**
     * @return list<ImportDefinition>
     */
    public function all(): array
    {
        return $this->registry->all();
    }

    public function find(string $key): ImportDefinition
    {
        return $this->registry->find($key);
    }

    public function has(string $key): bool
    {
        return $this->registry->has($key);
    }

    public function describe(string $key, ?string $locale = null): ImporterDescriptor
    {
        return $this->registry->describe($key, $locale);
    }

    /**
     * The importers `$user` may view for `$owner`, described in the requested locale.
     *
     * @return list<ImporterDescriptor>
     */
    public function available(?Authenticatable $user, Model $owner, ?string $locale = null): array
    {
        return array_map(
            static fn (ImportDefinition $definition): ImporterDescriptor => ImporterRegistry::descriptor($definition, $locale),
            $this->registry->available($user, $owner),
        );
    }
}
