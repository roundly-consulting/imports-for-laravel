<?php

declare(strict_types=1);

namespace Workbench\App\Importers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\Field;
use RoundlyConsulting\Imports\Definition\NaturalKey;
use RoundlyConsulting\Imports\Definition\Ownership;
use RoundlyConsulting\Imports\Enums\ImportOperation;
use RoundlyConsulting\Imports\Enums\RollbackOutcome;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\ImportContext;
use RoundlyConsulting\Imports\Rows\ImportRow;
use Workbench\App\Hooks;
use Workbench\App\Invitations;
use Workbench\App\Models\Invitation;
use Workbench\App\Models\User;

/**
 * People join a team by invitation: the importer creates invitations through the host's own
 * callback, and undo revokes them instead of deleting them (an accepted one is kept). Its key and
 * label come from the class name; the assets importer declares both.
 */
final class PeopleImporter extends Importer
{
    // key() and label() default from the class name: `people`, `People`.

    public function model(): string
    {
        return Invitation::class;
    }

    public function ownership(): Ownership
    {
        return Ownership::column('team_id');
    }

    public function naturalKey(): NaturalKey
    {
        return NaturalKey::by('email')->caseInsensitive();
    }

    public function fields(): array
    {
        return [
            Field::email('email')->label(['en' => 'Email', 'sk' => 'E-mail'])->required()->example('jane@example.com'),
            Field::text('first_name')->label(['en' => 'First name', 'sk' => 'Meno'])->example('Jane'),
            Field::text('last_name')->label(['en' => 'Last name', 'sk' => 'Priezvisko'])->example('Doe'),
            Field::choice('role')->label(['en' => 'Role', 'sk' => 'Rola'])->options([
                'admin' => ['en' => 'Administrator', 'sk' => 'Administrátor'],
                'member' => ['en' => 'Member', 'sk' => 'Člen'],
                'viewer' => ['en' => 'Viewer', 'sk' => 'Pozorovateľ'],
            ])->default('member'),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return $user instanceof User && $user->is_admin && $user->team_id === $owner->getKey();
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        Hooks::call('create', $row->number());

        $email = $row->get('email');
        $role = $row->get('role');
        $first = $row->get('first_name');
        $last = $row->get('last_name');

        return Invitations::invite(
            $context->owner,
            is_string($email) ? $email : '',
            is_string($role) ? $role : 'member',
            is_string($first) ? $first : null,
            is_string($last) ? $last : null,
        );
    }

    public function update(Model $record, ImportRow $row, ImportContext $context): Model
    {
        Hooks::call('update', $row->number());

        if ($record->getAttribute('status') === 'pending') {
            $record->forceFill(['role' => $row->get('role')])->save();
        }

        return $record;
    }

    public function rollback(Model $record, ImportContext $context): RollbackOutcome
    {
        Hooks::call('rollback', 0);

        if (! $record instanceof Invitation || $record->status === 'accepted') {
            return RollbackOutcome::Kept;
        }

        $record->revoke();

        return RollbackOutcome::Handled;
    }
}
