<?php
// Aggiunge la raccolta riservata kris_notfound con i testi della pagina 404:
// ogni sito ha la sua 404 gestita fin dall'inizio, con testi predefiniti.
// Il template template/404.html del tema (se c'e) li usa come campi; senza,
// li usa la 404 di serie di Kris. Solo aggiunte: i valori gia presenti non
// vengono toccati.
return [
    'version'     => 6,
    'description' => 'Aggiunge la sezione Pagina 404.',
    'auto'        => true,
    'up'          => static function (array $files): array {
        $schema = [
            ['name' => 'title', 'type' => 'text', 'description' => 'Titolo della pagina 404'],
            ['name' => 'text', 'type' => 'text', 'description' => 'Testo sotto il titolo'],
            ['name' => 'button_label', 'type' => 'text', 'description' => 'Pulsante: testo'],
            ['name' => 'button_url', 'type' => 'plain', 'description' => 'Pulsante: link (di solito la homepage)'],
            ['name' => 'image', 'type' => 'image', 'description' => 'Immagine (facoltativa)'],
        ];
        $defaults = [
            'title' => ['it' => 'Pagina non trovata', 'en' => 'Page not found'],
            'text' => ['it' => 'La pagina che cerchi non esiste o è stata spostata.', 'en' => "The page you're looking for doesn't exist or has been moved."],
            'button_label' => ['it' => 'Torna alla home', 'en' => 'Back to home'],
        ];
        $langs = $files['cms_settings']['languages'] ?? ['it', 'en'];
        $langs = is_array($langs) && $langs ? array_values($langs) : ['it', 'en'];

        $model = $files['k_model']['kris_notfound'] ?? [];
        $model = is_array($model) ? $model : [];
        $known = array_column($model, 'name');
        foreach ($schema as $field) {
            if (!in_array($field['name'], $known, true)) {
                $model[] = $field;
            }
        }
        $files['k_model']['kris_notfound'] = $model;

        $index = null;
        foreach ($files['k_data'] as $i => $entity) {
            if (is_array($entity) && ($entity['name'] ?? null) === 'kris_notfound' && (int) ($entity['id'] ?? -1) === 0) {
                $index = $i;
                break;
            }
        }
        $entity = $index === null ? ['id' => 0, 'name' => 'kris_notfound', 'data' => []] : $files['k_data'][$index];
        $present = array_column(is_array($entity['data'] ?? null) ? $entity['data'] : [], 'name');
        foreach ($schema as $field) {
            if (in_array($field['name'], $present, true)) continue;
            if ($field['type'] === 'plain') {
                $value = $field['name'] === 'button_url' ? 'index.php' : '';
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
