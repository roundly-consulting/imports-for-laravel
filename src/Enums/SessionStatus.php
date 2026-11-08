<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where an import session stands (see the status machine in the docs).
 */
#[TranslatedLabels('imports::enums.session_status')]
enum SessionStatus: string
{
    use Helpers;

    case Uploaded = 'uploaded';
    case NeedsMapping = 'needs_mapping';
    case Mapped = 'mapped';
    case Validating = 'validating';
    case Previewed = 'previewed';
    case PreviewFailed = 'preview_failed';
    case Committing = 'committing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';
    case RollbackFailed = 'rollback_failed';
    case Discarded = 'discarded';
    case Expired = 'expired';

    /**
     * The statuses before a commit: source, mapping, mode and preview can still change.
     *
     * @return list<self>
     */
    public static function beforeCommit(): array
    {
        return [self::Uploaded, self::NeedsMapping, self::Mapped, self::Validating, self::Previewed, self::PreviewFailed];
    }

    /**
     * @return list<self>
     */
    public static function undoable(): array
    {
        return [self::Completed, self::Failed, self::Cancelled];
    }

    public function isBeforeCommit(): bool
    {
        return in_array($this, self::beforeCommit(), true);
    }
}
