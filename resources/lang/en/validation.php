<?php

declare(strict_types=1);

return [
    'extra_values' => 'The row has values beyond the last column with a header.',
    'too_long' => ':attribute is longer than :max characters.',
    'invalid_value' => ':attribute: ":value" is not a valid value.',
    'invalid_integer' => ':attribute: ":value" is not a whole number.',
    'integer_overflow' => ':attribute: ":value" is too large.',
    'invalid_number' => ':attribute: ":value" is not a number.',
    'invalid_boolean' => ':attribute: ":value" is not yes or no.',
    'invalid_date' => ':attribute: ":value" is not a valid date.',
    'invalid_datetime' => ':attribute: ":value" is not a valid date and time.',
    'invalid_choice' => ':attribute: ":value" is not one of: :values.',
    'spreadsheet_error' => ':attribute: the cell holds a spreadsheet error (:code).',
    'unknown_reference' => ':attribute: ":value" was not found.',
    'ambiguous_reference' => ':attribute: ":value" matches more than one record.',
    'ambiguous_match' => 'More than one existing record matches this row (:key).',
    'conflicting_match' => 'The row\'s identifiers match different existing records (:key).',
    'duplicate_in_file' => 'An earlier row of the file has the same identifier.',
    'same_record' => 'An earlier row of the file matches the same existing record.',
    'unsaved' => 'The record was not saved.',
    'other_connection' => 'The record was saved on another database connection.',
    'outside_owner' => 'The record would be outside this import\'s owner.',
    'callback_failed' => 'The row could not be imported.',
    'duplicate_created_meanwhile' => 'A record with the same identifier was created meanwhile.',
    'empty_file' => 'The file is empty.',
    'extension_not_allowed' => 'Files of type ":extension" are not accepted (allowed: :allowed).',
    'file_too_large' => 'The file is larger than :max.',
    'duplicate_file' => 'The same file appears earlier in this upload.',
    'file_imported_earlier' => 'This file was imported before.',
];
