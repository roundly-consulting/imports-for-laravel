<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Tests\Http;

use RoundlyConsulting\Imports\Tests\Support\TeamOwnerResolver;
use RoundlyConsulting\Imports\Tests\TestCase;

/**
 * The JSON API switched on (routes load at boot), owned by the user's team.
 */
abstract class HttpTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'imports.routes.enabled' => 'on',
            'imports.routes.middleware' => ['api', 'auth'],
            'imports.routes.owner_resolver' => TeamOwnerResolver::class,
        ];
    }
}
