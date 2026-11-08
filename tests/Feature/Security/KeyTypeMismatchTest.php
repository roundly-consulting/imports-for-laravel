<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\Exceptions\InvalidImportsConfigurationException;
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Support\KeyTypes;
use RoundlyConsulting\Imports\Testing\SpreadsheetFactory;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use Workbench\App\Models\Team;

test('a_mismatched_owner_key_type_fails_fast_naming_the_config_key', function (): void {
    $owner = new Team;
    $owner->setKeyType('string')->forceFill(['id' => '01JC0000000000000000000000'])->exists = true;

    expect(fn () => Imports::for($owner)->upload('assets', SpreadsheetFactory::csv(['Name'], [['x']])))
        ->toThrow(InvalidImportsConfigurationException::class, 'The Team key does not match configuration value [imports.key_type] (bigint).');

    config()->set('imports.key_type', 'uuid');
    expect(fn () => KeyTypes::assert(team(), 'owner'))->toThrow(InvalidImportsConfigurationException::class, '[imports.key_type] (uuid)');

    config()->set('imports.record_key_type', 'ulid');
    expect(fn () => KeyTypes::assert(team(), 'record'))->toThrow(InvalidImportsConfigurationException::class, '[imports.record_key_type] (ulid)')
        ->and(KeyTypes::fits('9999999999999999999', KeyType::BigInt))->toBeFalse()
        ->and(KeyTypes::fits(5, KeyType::BigInt))->toBeTrue();
});
