<?php

declare(strict_types=1);

namespace Workbench\App;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\Jobs\SendInvitation;
use Workbench\App\Models\Invitation;

/**
 * The host app's own invitation service: a pending invitation is reused, a new one queues its
 * mail after commit.
 */
final class Invitations
{
    public static function invite(Model $team, string $email, string $role, ?string $first = null, ?string $last = null): Invitation
    {
        $invitation = Invitation::query()->firstOrCreate(
            ['team_id' => $team->getKey(), 'email' => mb_strtolower($email), 'status' => 'pending'],
            ['role' => $role, 'first_name' => $first, 'last_name' => $last],
        );

        if ($invitation->wasRecentlyCreated) {
            dispatch(new SendInvitation($invitation->id));
        }

        return $invitation;
    }
}
