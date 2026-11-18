<?php
// Aggiunge la raccolta riservata kris_wip, «Lavori in corso»: con l'interruttore
// acceso i visitatori ricevono una pagina 503 (il template template/kris_wip.html
// del tema, o quella di serie di Kris) mentre chi ha l'editor aperto vede il sito.
// Solo aggiunte: i valori gia presenti non vengono toccati.
return [
    'version'     => 7,
    'description' => 'Aggiunge la sezione Lavori in corso.',
    'auto'        => true,
    'up'          => static function (array $files): array {
        $schema = [
            ['name' => 'enabled', 'type' => 'boolean', 'description' => 'Sito in lavorazione: i visitatori vedono questa pagina'],
            ['name' => 'title', 'type' => 'text', 'description' => 'Titolo'],
            ['name' => 'text', 'type' => 'text', 'description' => 'Testo'],
        ];
        $defaults = [
            'title' => ['it' => 'Sito in lavorazione', 'en' => 'Under construction'],
            'text' => ['it' => 'Stiamo preparando qualcosa di nuovo: torna a trovarci presto.', 'en' => "We're working on something new: please come back soon."],
        ];
        $langs = $files['cms_settings']['languages'] ?? ['it', 'en'];
        $langs = is_array($langs) && $langs ? array_values($langs) : ['it', 'en'];

        $model = $files['k_model']['kris_wip'] ?? [];
        $model = is_array($model) ? $model : [];
        $known = array_column($model, 'name');
        foreach ($schema as $field) {
            if (!in_array($field['name'], $known, true)) $model[] = $field;
        }
        $files['k_model']['kris_wip'] = $model;

        $index = null;
        foreach ($files['k_data'] as $i => $entity) {
            if (is_array($entity) && ($entity['name'] ?? null) === 'kris_wip' && (int) ($entity['id'] ?? -1) === 0) {
                $index = $i;
                break;
            }
        }
        $entity = $index === null ? ['id' => 0, 'name' => 'kris_wip', 'data' => []] : $files['k_data'][$index];
        $present = array_column(is_array($entity['data'] ?? null) ? $entity['data'] : [], 'name');
        foreach ($schema as $field) {
            if (in_array($field['name'], $present, true)) continue;
            if ($field['type'] === 'boolean') {
                $value = '';
            } else {
                $value = [];
                foreach ($langs as $lang) $value[$lang] = $defaults[$field['name']][$lang] ?? '';
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
