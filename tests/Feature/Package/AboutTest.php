<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('renders its about section', function (): void {
    expect('imports')->toLeakNoSecrets([], mustRender: ['Importers', '3', 'Queue', 'Disk', 'imports', 'Key type', 'bigint', 'Owner scope', 'ExplicitOwnerScope']);
});

it('reports the queue switched off and the routes on', function (): void {
    config()->set('imports.queue.enabled', 'off');
    config()->set('imports.routes.enabled', 'on');
    config()->set('imports.queue.connection', 'redis');

    Artisan::call('about', ['--only' => 'imports']);

    expect(Artisan::output())->toMatch('/Queue\W+OFF \(inline\)/')->toMatch('/Routes\W+ON/');

    config()->set('imports.queue.enabled', 'on');
    Artisan::call('about', ['--only' => 'imports']);

    expect(Artisan::output())->toMatch('/connection SET/');
});
