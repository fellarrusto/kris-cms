<?php
// Testi del secondo livello del banner cookie: scelte per categoria
// (contenuti di terze parti, statistiche, marketing), come chiedono le linee
// guida del Garante quando le finalita sono piu d'una. Solo aggiunte a
// kris_legal: campi e valori gia presenti non vengono toccati.
return [
    'version'     => 5,
    'description' => 'Aggiunge al banner cookie le scelte per categoria.',
    'auto'        => true,
    'up'          => static function (array $files): array {
        $schema = [
            ['name' => 'banner_customize', 'type' => 'text', 'description' => 'Banner cookie: pulsante per scegliere le categorie'],
            ['name' => 'banner_save', 'type' => 'text', 'description' => 'Banner cookie: pulsante per salvare le scelte'],
            ['name' => 'category_thirdparty', 'type' => 'text', 'description' => 'Categoria contenuti di terze parti: nome'],
            ['name' => 'category_thirdparty_text', 'type' => 'text', 'description' => 'Categoria contenuti di terze parti: descrizione (mappe, video, social incorporati)'],
            ['name' => 'category_statistics', 'type' => 'text', 'description' => 'Categoria statistiche: nome'],
            ['name' => 'category_statistics_text', 'type' => 'text', 'description' => 'Categoria statistiche: descrizione (per esempio Google Analytics)'],
            ['name' => 'category_marketing', 'type' => 'text', 'description' => 'Categoria marketing: nome'],
            ['name' => 'category_marketing_text', 'type' => 'text', 'description' => 'Categoria marketing: descrizione (per esempio Pixel di Meta)'],
        ];
        $defaults = [
            'banner_customize' => ['it' => 'Personalizza', 'en' => 'Customize'],
            'banner_save' => ['it' => 'Salva le scelte', 'en' => 'Save choices'],
            'category_thirdparty' => ['it' => 'Contenuti di terze parti', 'en' => 'Third-party content'],
            'category_thirdparty_text' => ['it' => 'Mappe, video e contenuti social incorporati nelle pagine.', 'en' => 'Maps, videos and social content embedded in the pages.'],
            'category_statistics' => ['it' => 'Statistiche', 'en' => 'Statistics'],
            'category_statistics_text' => ['it' => 'Ci aiutano a capire come viene usato il sito.', 'en' => 'They help us understand how the site is used.'],
            'category_marketing' => ['it' => 'Marketing', 'en' => 'Marketing'],
            'category_marketing_text' => ['it' => 'Servono a mostrarti annunci pertinenti su altri siti.', 'en' => 'They are used to show you relevant ads on other sites.'],
        ];
        $langs = $files['cms_settings']['languages'] ?? ['it', 'en'];
        $langs = is_array($langs) && $langs ? array_values($langs) : ['it', 'en'];

        // Schema: i campi mancanti si aggiungono in coda; senza kris_legal (sito molto vecchio) la crea la 0002
        if (!isset($files['k_model']['kris_legal']) || !is_array($files['k_model']['kris_legal'])) {
            return $files;
        }
        $known = array_column($files['k_model']['kris_legal'], 'name');
        foreach ($schema as $field) {
            if (!in_array($field['name'], $known, true)) {
                $files['k_model']['kris_legal'][] = $field;
            }
        }

        foreach ($files['k_data'] as $i => $entity) {
            if (!is_array($entity) || ($entity['name'] ?? null) !== 'kris_legal' || (int) ($entity['id'] ?? -1) !== 0) {
                continue;
            }
            $present = array_column(is_array($entity['data'] ?? null) ? $entity['data'] : [], 'name');
            foreach ($schema as $field) {
                if (in_array($field['name'], $present, true)) continue;
                $value = [];
                foreach ($langs as $lang) {
                    $value[$lang] = $defaults[$field['name']][$lang] ?? '';
                }
                $entity['data'][] = ['name' => $field['name'], 'type' => $field['type'], 'value' => $value];
            }
            $files['k_data'][$i] = $entity;
        }
        return $files;
    },
];
