<?php
// La 0009 dava ai tipi nati da un elenco (i "promossi", come i progetti o i siti
// di un portfolio) come nome visibile la descrizione del campo della 1.x, spesso
// una frase lunga. Qui una descrizione lunga diventa la descrizione del tipo e
// il nome visibile si ricava dal nome tecnico (websites → Websites). Solo lo
// schema: contenuti e template non cambiano.
return [
    'version'     => 10,
    'description' => 'Nomi brevi per i tipi nati dagli elenchi della 1.x.',
    'auto'        => true,
    'up'          => static function (array $files): array {
        if (!isset($files['schema'])) return $files;
        foreach (is_array($files['schema']['promoted'] ?? null) ? $files['schema']['promoted'] : [] as $p) {
            $name = is_array($p) && is_string($p['to'] ?? null) ? $p['to'] : null;
            $type = $name !== null ? ($files['schema']['types'][$name] ?? null) : null;
            if (!is_array($type) || !is_string($type['label'] ?? null)) continue;
            $label = trim($type['label']);
            if (mb_strlen($label) <= 40 && !preg_match('/[.:;!?]\s*$/u', $label)) continue;
            if (!is_string($type['description'] ?? null) || $type['description'] === '') $type['description'] = $label;
            $type['label'] = ucfirst(str_replace('_', ' ', $name));
            // label prima di description, come nelle altre raccolte
            $files['schema']['types'][$name] = ['label' => $type['label'], 'description' => $type['description']] + $type;
        }
        return $files;
    },
];
