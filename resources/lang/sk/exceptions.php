<?php

declare(strict_types=1);

return [
    'not_found' => 'Import sa nenašiel.',
    'batch_not_found' => 'Beh importu sa nenašiel.',
    'unknown_importer' => 'Import [:importer] neexistuje.',
    'unauthorized' => 'Na operáciu :operation s týmto importom nemáte oprávnenie.',
    'undo_window_closed' => 'Tento import už nemožno vrátiť.',
    'mode_not_supported' => 'Režim [:mode] nie je pre [:importer] dostupný.',
    'too_many_mappings' => 'Pre jeden import možno uložiť najviac :limit mapovaní. Najprv niektoré odstráňte.',
    'mappings_too_large' => 'Uložené mapovania by boli príliš veľké. Najprv niektoré odstráňte.',
    'mapping_name' => 'Názov mapovania musí mať 1 až 60 znakov.',
    'mappings_disabled' => 'Ukladanie mapovaní je vypnuté.',
    'mapping_unknown_field' => 'Import nemá pole [:field].',
    'mapping_field_twice' => 'Pole [:field] je namapované dvakrát.',
    'mapping_empty' => 'Mapovanie musí priradiť aspoň jeden stĺpec.',
    'wrong_status' => 'Import to v stave :status nedokáže.',
    'stale_preview' => 'Náhľad je zastaraný. Pred potvrdením ho spustite znova.',
    'expired' => 'Platnosť importu vypršala. Nahrajte súbor znova.',
    'file_pruned' => 'Nahraný súbor už nie je k dispozícii.',
    'already_running' => 'Pre tohto vlastníka už prebieha iný import tohto druhu.',
    'sheet_not_found' => 'Hárok [:sheet] neexistuje. Hárky: :sheets.',
    'invalid_mapping' => 'Mapovanie stĺpcov nie je platné.',
    'mapping' => [
        'ambiguous' => 'Viac stĺpcov sa volá „:column“; namapujte ho podľa čísla stĺpca.',
        'unknown_column' => 'Stĺpec „:column“ neexistuje.',
        'unknown_field' => 'Pole „:field“ neexistuje.',
        'mapped_twice' => 'Pole „:field“ je namapované na viac stĺpcov.',
        'required_unmapped' => 'Povinné pole „:field“ nie je namapované.',
        'empty' => 'Nie je čo uložiť.',
        'incomplete' => 'Pred náhľadom namapujte všetky povinné polia.',
    ],
];
