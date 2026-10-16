<?php
// Aggiunge la raccolta riservata kris_site con la favicon del sito. Kris la
// inserisce da solo nell'<head> di ogni pagina, se il template non ne ha gia
// una: i template non devono fare nulla. Solo aggiunte: campi e valori gia
// presenti non vengono toccati.
return [
    'version'     => 4,
    'description' => 'Aggiunge la favicon del sito, modificabile dall\'editor.',
    'auto'        => true,
    'up'          => static function (array $files): array {
        $schema = [
            ['name' => 'favicon', 'type' => 'image', 'description' => 'Favicon: l\'icona del sito nella scheda del browser (PNG quadrato, almeno 48×48 px)'],
        ];
        $langs = $files['cms_settings']['languages'] ?? ['it', 'en'];
        $langs = is_array($langs) && $langs ? array_values($langs) : ['it', 'en'];

        $model = $files['k_model']['kris_site'] ?? [];
        $model = is_array($model) ? $model : [];
        $known = array_column($model, 'name');
        foreach ($schema as $field) {
            if (!in_array($field['name'], $known, true)) {
                $model[] = $field;
            }
        }
        $files['k_model']['kris_site'] = $model;

        $index = null;
        foreach ($files['k_data'] as $i => $entity) {
            if (is_array($entity) && ($entity['name'] ?? null) === 'kris_site' && (int) ($entity['id'] ?? -1) === 0) {
                $index = $i;
                break;
            }
        }
        $entity = $index === null ? ['id' => 0, 'name' => 'kris_site', 'data' => []] : $files['k_data'][$index];
        $present = array_column(is_array($entity['data'] ?? null) ? $entity['data'] : [], 'name');
        foreach ($schema as $field) {
            if (!in_array($field['name'], $present, true)) {
                $entity['data'][] = ['name' => $field['name'], 'type' => $field['type'], 'value' => array_fill_keys($langs, '')];
            }
        }
        if ($index === null) {
            $files['k_data'][] = $entity;
        } else {
            $files['k_data'][$index] = $entity;
        }
        return $files;
    },
];
