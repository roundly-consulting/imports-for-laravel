<?php

declare(strict_types=1);

use Orchestra\Testbench\Foundation\Config;
use RoundlyConsulting\Imports\ImportsServiceProvider;

it('registers the package provider in the app Larastan boots', function (): void {
    $config = Config::loadFromYaml(dirname(__DIR__, 3));

    expect($config->getExtraAttributes()['providers'])->toBe([ImportsServiceProvider::class]);
});
