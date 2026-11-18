<?php
// Allarga kris_site, che aveva solo la favicon, alle proprietà del sito usate
// nell'<head> di ogni pagina: nome, modello del titolo, descrizione e immagine
// di condivisione predefinite, codici di verifica dei motori di ricerca.
// Kris li aggiunge solo dove il template non ha già quei tag. Solo aggiunte.
return [
    'version'     => 8,
    'description' => 'Aggiunge le proprietà del sito (nome, titolo, condivisione, verifiche).',
    'auto'        => true,
    'up'          => static function (array $files): array {
        $schema = [
            ['name' => 'site_name', 'type' => 'text', 'description' => 'Nome del sito', 'section' => 'Motori di ricerca e condivisione'],
            ['name' => 'title_template', 'type' => 'plain', 'description' => 'Modello del titolo delle pagine: {title} è il titolo della pagina, {site} il nome del sito', 'section' => 'Motori di ricerca e condivisione'],
            ['name' => 'description', 'type' => 'text', 'description' => 'Descrizione predefinita, per le pagine senza la loro', 'section' => 'Motori di ricerca e condivisione'],
            ['name' => 'share_image', 'type' => 'image', 'description' => 'Immagine di condivisione predefinita (1200×630 px)', 'section' => 'Motori di ricerca e condivisione'],
            ['name' => 'google_verification', 'type' => 'plain', 'description' => 'Codice di verifica di Google Search Console (solo il valore di content)', 'section' => 'Verifiche'],
            ['name' => 'bing_verification', 'type' => 'plain', 'description' => 'Codice di verifica di Bing Webmaster Tools (solo il valore di content)', 'section' => 'Verifiche'],
        ];
        $langs = $files['cms_settings']['languages'] ?? ['it', 'en'];
        $langs = is_array($langs) && $langs ? array_values($langs) : ['it', 'en'];

        $model = $files['k_model']['kris_site'] ?? [];
        $model = is_array($model) ? $model : [];
        $known = array_column($model, 'name');
        foreach ($schema as $field) {
            if (!in_array($field['name'], $known, true)) $model[] = $field;
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
            if (in_array($field['name'], $present, true)) continue;
            $value = $field['type'] === 'plain' ? ($field['name'] === 'title_template' ? '{title}' : '') : array_fill_keys($langs, '');
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
