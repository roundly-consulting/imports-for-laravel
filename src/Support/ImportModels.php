<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Support;

use RoundlyConsulting\Imports\Models\ImportBatch;
use RoundlyConsulting\Imports\Models\ImportedRecord;
use RoundlyConsulting\Imports\Models\ImportRowResult;
use RoundlyConsulting\Imports\Models\ImportSession;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The model seam: the only place that reads `imports.models.*`. A host swaps a model by extending
 * the packaged class and naming the subclass in config.
 *
 * @internal
 */
final class ImportModels
{
    /** @return class-string<ImportSession> */
    public static function session(): string
    {
        return ModelResolver::for('imports.models.session', ImportSession::class);
    }

    /** @return class-string<ImportBatch> */
    public static function batch(): string
    {
        return ModelResolver::for('imports.models.batch', ImportBatch::class);
    }

    /** @return class-string<ImportRowResult> */
    public static function rowResult(): string
    {
        return ModelResolver::for('imports.models.row_result', ImportRowResult::class);
    }

    /** @return class-string<ImportedRecord> */
    public static function record(): string
    {
        return ModelResolver::for('imports.models.record', ImportedRecord::class);
    }
}
