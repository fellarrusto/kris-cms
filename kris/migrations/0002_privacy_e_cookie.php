<?php
// Aggiunge la raccolta riservata kris_legal: dati del titolare, informative
// privacy e cookie, testi del banner. Lo schema e del framework (non si
// modifica da Struttura) e i template la usano se vogliono, con k-component.
// Solo aggiunte: campi e valori gia presenti non vengono toccati.
return [
    'version'     => 2,
    'description' => 'Aggiunge la sezione Privacy e cookie.',
    'auto'        => true,
    'up'          => static function (array $files): array {
        $schema = [
            ['name' => 'owner_name', 'type' => 'plain', 'description' => 'Titolare del trattamento (ragione sociale o nome)'],
            ['name' => 'owner_address', 'type' => 'plain', 'description' => 'Indirizzo del titolare'],
            ['name' => 'owner_vat', 'type' => 'plain', 'description' => 'Partita IVA o codice fiscale'],
            ['name' => 'privacy_email', 'type' => 'plain', 'description' => 'Email per le richieste sulla privacy'],
            ['name' => 'updated_at', 'type' => 'plain', 'description' => 'Data di ultimo aggiornamento delle informative'],
            ['name' => 'privacy_policy', 'type' => 'richtext', 'description' => 'Informativa privacy'],
            ['name' => 'cookie_policy', 'type' => 'richtext', 'description' => 'Cookie policy'],
            ['name' => 'banner_title', 'type' => 'text', 'description' => 'Banner cookie: titolo'],
            ['name' => 'banner_text', 'type' => 'text', 'description' => 'Banner cookie: testo'],
            ['name' => 'banner_accept', 'type' => 'text', 'description' => 'Banner cookie: pulsante per accettare'],
            ['name' => 'banner_reject', 'type' => 'text', 'description' => 'Banner cookie: pulsante per rifiutare'],
            ['name' => 'banner_more', 'type' => 'text', 'description' => 'Banner cookie: link all\'informativa'],
        ];
        $defaults = [
            'banner_accept' => ['it' => 'Accetta', 'en' => 'Accept'],
            'banner_reject' => ['it' => 'Rifiuta', 'en' => 'Reject'],
            'banner_more'   => ['it' => 'Cookie policy', 'en' => 'Cookie policy'],
        ];
        $langs = $files['cms_settings']['languages'] ?? ['it', 'en'];
        $langs = is_array($langs) && $langs ? array_values($langs) : ['it', 'en'];

        // Schema: si aggiungono i campi mancanti, in coda, senza toccare gli altri.
        $model = $files['k_model']['kris_legal'] ?? [];
        $model = is_array($model) ? $model : [];
        $known = array_column($model, 'name');
        foreach ($schema as $field) {
            if (!in_array($field['name'], $known, true)) {
                $model[] = $field;
            }
        }
        $files['k_model']['kris_legal'] = $model;

        // Dati: un'unica entita con id 0; valori vuoti, tranne le etichette dei pulsanti.
        $index = null;
        foreach ($files['k_data'] as $i => $entity) {
            if (is_array($entity) && ($entity['name'] ?? null) === 'kris_legal' && (int) ($entity['id'] ?? -1) === 0) {
                $index = $i;
                break;
            }
        }
        $entity = $index === null ? ['id' => 0, 'name' => 'kris_legal', 'data' => []] : $files['k_data'][$index];
        $present = array_column(is_array($entity['data'] ?? null) ? $entity['data'] : [], 'name');
        foreach ($schema as $field) {
            if (in_array($field['name'], $present, true)) {
                continue;
            }
            if ($field['type'] === 'plain') {
                $value = '';
            } else {
                $value = [];
                foreach ($langs as $lang) {
                    $value[$lang] = $defaults[$field['name']][$lang] ?? '';
                }
            }
            $entity['data'][] = ['name' => $field['name'], 'type' => $field['type'], 'value' => $value];
        }
        if ($index === null) {
            $files['k_data'][] = $entity;
        } else {
            $files['k_data'][$index] = $entity;
        }
        return $files;
    },
];
