<?php

declare(strict_types=1);

use RoundlyConsulting\Imports\ImportsManager;
use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\Imports');

// The manager is non-final so the fake can extend it; the four models are swappable. Importer,
// FileImporter and the base definition are abstract (the host extends them).
ArchPresets::finalByDefault('RoundlyConsulting\Imports', [
    ImportsManager::class,
    ImportSession::class,
    ImportBatch::class,
    ImportRowResult::class,
    ImportedRecord::class,
]);

ArchPresets::swappableModelsAreNotFinal([
    ImportSession::class => 'imports.models.session',
    ImportBatch::class => 'imports.models.batch',
    ImportRowResult::class => 'imports.models.row_result',
    ImportedRecord::class => 'imports.models.record',
]);

// Every digest goes through crypto-for-laravel (Digests → Crypto::digest()).
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Imports');

ArchPresets::modelsResolveThroughSeam(__DIR__.'/../../src', 'Support', [
    'imports.models.session',
    'imports.models.batch',
    'imports.models.row_result',
    'imports.models.record',
]);

ArchPresets::factoriesResolveThroughSeam(__DIR__.'/../../database/factories', __DIR__.'/../../src');

ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

// The Dependency Policy as a test: php, ext-*, illuminate/* and roundly packages only.
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers();

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Imports');
