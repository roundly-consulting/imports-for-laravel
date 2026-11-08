<?php

declare(strict_types=1);

namespace RoundlyConsulting\Imports\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Why a whole file or archive was refused.
 */
#[TranslatedLabels('imports::enums.rejection_reason')]
enum RejectionReason: string
{
    use Helpers;

    case Empty = 'empty';
    case TooLarge = 'too_large';
    case MacroEnabled = 'macro_enabled';
    case OpenDocument = 'open_document';
    case Xlsb = 'xlsb';
    case UnsupportedFormat = 'unsupported_format';
    case LegacyOrEncrypted = 'legacy_or_encrypted';
    case Utf16 = 'utf16';
    case Utf32 = 'utf32';
    case Binary = 'binary';
    case NotUtf8 = 'not_utf8';
    case NoHeader = 'no_header';
    case TooManyColumns = 'too_many_columns';
    case TooManyRows = 'too_many_rows';
    case MalformedSpreadsheet = 'malformed_spreadsheet';
    case FormatNotAccepted = 'format_not_accepted';
    case TooManyFiles = 'too_many_files';
    case ArchiveTooLarge = 'archive_too_large';
    case ZipTooLarge = 'zip_too_large';
    case ZipRatio = 'zip_ratio';
    case ZipUnsafePath = 'zip_unsafe_path';
    case ZipSymlink = 'zip_symlink';
    case ZipEncrypted = 'zip_encrypted';
    case ZipInconsistent = 'zip_inconsistent';
    case ZipEntryTooLarge = 'zip_entry_too_large';
    case Unreadable = 'unreadable';
}
