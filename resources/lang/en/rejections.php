<?php

declare(strict_types=1);

return [
    'empty' => 'The file is empty.',
    'too_large' => 'The file is too large (:actual); the limit is :limit.',
    'macro_enabled' => 'Macro-enabled workbooks (.xlsm) are not accepted. Save the file as .xlsx.',
    'open_document' => 'OpenDocument spreadsheets (.ods) are not supported. Save the file as .xlsx or as CSV UTF-8.',
    'xlsb' => 'Binary Excel workbooks (.xlsb) are not supported. Save the file as .xlsx.',
    'unsupported_format' => 'This file is not a CSV or XLSX spreadsheet.',
    'legacy_or_encrypted' => 'This is an Excel 97–2003 workbook or a password-protected file. Save it as .xlsx without a password.',
    'utf16' => 'The file is saved as UTF-16 (Unicode text). In Excel choose "CSV UTF-8 (comma delimited)" and upload it again.',
    'utf32' => 'The file is saved as UTF-32. Save it as CSV UTF-8 and upload it again.',
    'binary' => 'The file is not a text CSV or an XLSX workbook.',
    'not_utf8' => 'The file is not UTF-8 (on line :line it looks like :guess). In Excel choose "CSV UTF-8 (comma delimited)"; in LibreOffice pick the "Unicode (UTF-8)" character set.',
    'no_header' => 'Row :row, where the header should be, is empty.',
    'too_many_columns' => 'The file has :actual columns; the limit is :limit.',
    'too_many_rows' => 'The file has more than :limit data rows.',
    'malformed_spreadsheet' => 'The spreadsheet is damaged or not a valid XLSX workbook.',
    'data_after_blank_rows' => 'Row :row has data below more than :limit empty rows. Remove the empty rows or move that data to its own sheet.',
    'format_not_accepted' => 'This importer does not accept :format files.',
    'too_many_files' => 'There are :actual files; at most :limit can be uploaded at once.',
    'archive_too_large' => 'The archive is too large; the limit is :limit.',
    'zip_too_large' => 'The archive unpacks to more than is allowed.',
    'zip_ratio' => 'The archive is compressed suspiciously well and was refused.',
    'zip_unsafe_path' => 'The archive contains an unsafe file name: :name.',
    'zip_symlink' => 'The archive contains a symbolic link: :name.',
    'zip_encrypted' => 'The archive contains an encrypted file: :name.',
    'zip_inconsistent' => 'The archive is damaged or inconsistent.',
    'zip_entry_too_large' => 'A file in the archive is too large: :name.',
    'unreadable' => 'The file could not be read.',
    'encodings' => [
        'windows-1250' => 'Windows-1250 (Central European)',
        'windows-1252' => 'Windows-1252 (Western European)',
        'iso-8859' => 'ISO-8859',
    ],
];
