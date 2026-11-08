<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The invitation mail, queued after commit: a rolled-back chunk never sends it.
 */
final class SendInvitation implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invitationId)
    {
        $this->afterCommit();
        $this->onQueue('mail');
    }

    public function handle(): void {}
}
