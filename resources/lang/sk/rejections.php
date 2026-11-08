<?php

declare(strict_types=1);

return [
    'empty' => 'Súbor je prázdny.',
    'too_large' => 'Súbor je príliš veľký (:actual); limit je :limit.',
    'macro_enabled' => 'Zošity s makrami (.xlsm) sa neprijímajú. Uložte súbor ako .xlsx.',
    'open_document' => 'Tabuľky OpenDocument (.ods) nie sú podporované. Uložte súbor ako .xlsx alebo ako CSV UTF-8.',
    'xlsb' => 'Binárne zošity Excelu (.xlsb) nie sú podporované. Uložte súbor ako .xlsx.',
    'unsupported_format' => 'Tento súbor nie je tabuľka CSV ani XLSX.',
    'legacy_or_encrypted' => 'Ide o zošit Excel 97–2003 alebo o súbor chránený heslom. Uložte ho ako .xlsx bez hesla.',
    'utf16' => 'Súbor je uložený v kódovaní UTF-16 (text Unicode). V Exceli zvoľte „CSV UTF-8 (oddelený čiarkami)“ a nahrajte ho znova.',
    'utf32' => 'Súbor je uložený v kódovaní UTF-32. Uložte ho ako CSV UTF-8 a nahrajte ho znova.',
    'binary' => 'Súbor nie je textový CSV ani zošit XLSX.',
    'not_utf8' => 'Súbor nie je v kódovaní UTF-8 (na riadku :line vyzerá ako :guess). V Exceli zvoľte „CSV UTF-8 (oddelený čiarkami)“; v LibreOffice zvoľte znakovú sadu „Unicode (UTF-8)“.',
    'no_header' => 'Riadok :row, kde má byť hlavička, je prázdny.',
    'too_many_columns' => 'Súbor má :actual stĺpcov; limit je :limit.',
    'too_many_rows' => 'Súbor má viac ako :limit dátových riadkov.',
    'malformed_spreadsheet' => 'Tabuľka je poškodená alebo nie je platným zošitom XLSX.',
    'data_after_blank_rows' => 'Riadok :row obsahuje údaje pod viac ako :limit prázdnymi riadkami. Odstráňte prázdne riadky alebo presuňte tieto údaje na samostatný hárok.',
    'format_not_accepted' => 'Tento import neprijíma súbory :format.',
    'too_many_files' => 'Súborov je :actual; naraz ich možno nahrať najviac :limit.',
    'archive_too_large' => 'Archív je príliš veľký; limit je :limit.',
    'zip_too_large' => 'Archív sa rozbalí na viac, než je povolené.',
    'zip_ratio' => 'Archív je podozrivo silno komprimovaný, a preto bol odmietnutý.',
    'zip_unsafe_path' => 'Archív obsahuje nebezpečný názov súboru: :name.',
    'zip_symlink' => 'Archív obsahuje symbolický odkaz: :name.',
    'zip_encrypted' => 'Archív obsahuje zašifrovaný súbor: :name.',
    'zip_inconsistent' => 'Archív je poškodený alebo nekonzistentný.',
    'zip_entry_too_large' => 'Súbor v archíve je príliš veľký: :name.',
    'unreadable' => 'Súbor sa nepodarilo prečítať.',
    'encodings' => [
        'windows-1250' => 'Windows-1250 (stredoeurópske)',
        'windows-1252' => 'Windows-1252 (západoeurópske)',
        'iso-8859' => 'ISO-8859',
    ],
];
