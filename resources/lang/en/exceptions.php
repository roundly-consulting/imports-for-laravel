<?php

declare(strict_types=1);

return [
    'not_found' => 'The import was not found.',
    'batch_not_found' => 'The import run was not found.',
    'unknown_importer' => 'There is no importer [:importer].',
    'unauthorized' => 'You are not allowed to :operation this import.',
    'undo_window_closed' => 'This import can no longer be undone.',
    'mode_not_supported' => 'The mode [:mode] is not available for [:importer].',
    'too_many_mappings' => 'At most :limit mappings can be saved for an importer. Delete one first.',
    'mappings_too_large' => 'The saved mappings would be too large. Delete some first.',
    'mapping_name' => 'A mapping name must have 1 to 60 characters.',
    'mappings_disabled' => 'Saved mappings are switched off.',
    'mapping_unknown_field' => 'The importer has no field [:field].',
    'mapping_field_twice' => 'The field [:field] is mapped twice.',
    'mapping_empty' => 'A mapping must map at least one column.',
    'wrong_status' => 'The import cannot do this while it is :status.',
    'stale_preview' => 'The preview is out of date. Run it again before committing.',
    'expired' => 'The import has expired. Upload the file again.',
    'file_pruned' => 'The uploaded file is no longer available.',
    'already_running' => 'Another import of this kind is already running for this owner.',
    'sheet_not_found' => 'There is no sheet [:sheet]. Sheets: :sheets.',
    'invalid_mapping' => 'The column mapping is not valid.',
    'mapping' => [
        'ambiguous' => 'More than one column is named ":column"; map it by column number.',
        'header_or_index' => '":column" is a column header and the number of another column; map it explicitly by column number.',
        'unknown_column' => 'There is no column ":column".',
        'unknown_field' => 'There is no field ":field".',
        'mapped_twice' => 'The field ":field" is mapped to more than one column.',
        'required_unmapped' => 'The required field ":field" is not mapped.',
        'empty' => 'There is no mapping to save.',
        'incomplete' => 'Map every required field before the preview.',
    ],
];
