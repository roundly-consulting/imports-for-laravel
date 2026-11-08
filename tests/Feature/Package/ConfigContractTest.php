<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

it('ships exactly the config keys it reads', function (): void {
    // The CSV template delimiter is a locale map read by the requested locale: its locale
    // entries are data, not keys a literal read could name.
    expect(__DIR__.'/../config/imports.php')->toSatisfyConfigContract(__DIR__.'/../src', [
        'allowUnread' => ['imports.templates.csv_delimiter.default', 'imports.templates.csv_delimiter.sk'],
    ]);
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/'.basename(__DIR__.'/../config/imports.php'))->not->toBeFile();
});
