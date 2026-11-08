<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\InvitationFactory;

/**
 * A pending invitation to a team — what the people importer creates through its callback.
 *
 * @property int $id
 * @property int $team_id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $role
 * @property string $status
 */
final class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    protected $guarded = [];

    public function revoke(): void
    {
        $this->forceFill(['status' => 'revoked'])->save();
    }

    public function accept(): void
    {
        $this->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();
    }

    protected static function newFactory(): InvitationFactory
    {
        return InvitationFactory::new();
    }
}
