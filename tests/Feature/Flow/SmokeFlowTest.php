<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Enums\BatchStatus;
use RoundlyConsulting\Imports\Enums\SessionStatus;
use Workbench\App\Models\Asset;

it('runs upload, preview, commit and rollback', function (): void {
    $team = team();
    $user = admin($team);

    $session = uploadAssets($team, $user, assetsCsv(assetRows(5)));
    expect($session->status())->toBe(SessionStatus::Mapped);

    $session->preview();
    expect($session->status())->toBe(SessionStatus::Previewed)
        ->and($session->counts()->valid)->toBe(5);

    $batch = $session->commit();
    expect($batch->status())->toBe(BatchStatus::Completed)
        ->and(Asset::query()->count())->toBe(5)
        ->and($batch->summary()->created)->toBe(5);

    $batch->rollback();
    expect(Asset::query()->count())->toBe(0)
        ->and($batch->rollbackReport()?->deleted)->toBe(5);
});
