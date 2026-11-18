<?php
declare(strict_types=1);

if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * L'editor di oggi e i dati del formato 2.
 *
 * I dati del sito sono nel formato 2 (schema, content, settings: vedi
 * docs/2.0/specifica.md, 2). Le schermate dell'editor lavorano ancora sulla
 * forma della 1.x (contenuti come liste {name, type, value}, schema per
 * raccolta, raccolte in cms_settings): qui si passa dall'una all'altra,
 * all'apertura e a ogni salvataggio. Nient'altro nel framework vede la forma
 * della 1.x.
 *
 * shortcut: l'editor non è ancora riscritto sul formato 2; lo sarà nel rifacimento
 * dell'editor (task 8/19). Fino ad allora questo file è l'unico punto di contatto.
 */

/**
 * I documenti della 1.x come li usa l'editor: data, model, cms_settings.
 *
 * @param array{schema: array, content: array, settings: array} $site
 * @return array{data: array, model: array, cms_settings: array}
 */
function editorFormat1(array $site): array
{
    $schema = $site['schema'];
    $settings = $site['settings'];
    $langs = is_array($settings['languages'] ?? null) && $settings['languages'] ? array_values($settings['languages']) : ['it', 'en'];

    $model = [];
    $collections = [];
    $posts = [];
    foreach (is_array($schema['types'] ?? null) ? $schema['types'] : [] as $type => $t) {
        $model[$type] = array_map('editorFormat1Field', array_values(array_filter((array) ($t['fields'] ?? []), 'is_array')));
        $info = array_intersect_key($t, ['label' => 1, 'description' => 1, 'single' => 1]);
        if ($info) $collections[$type] = $info;
        if (!empty($t['newest_first'])) $posts[] = $type;
    }
    $data = [];
    foreach (is_array($site['content'] ?? null) ? $site['content'] : [] as $item) {
        if (!is_array($item)) continue;
        $type = (string) ($item['type'] ?? '');
        $data[] = ['id' => (int) ($item['id'] ?? 0), 'name' => $type] + (!empty($item['hidden']) ? ['hidden' => true] : [])
            + ['data' => editorFormat1Data((array) ($item['values'] ?? []), (array) ($schema['types'][$type]['fields'] ?? []), $langs)];
    }
    $cms = $settings + ['languages' => $langs];
    if ($collections) $cms['collections'] = $collections;
    $cms['collection_order'] = array_values(array_filter(array_keys($model), fn($n) => !str_starts_with((string) $n, 'kris_')));
    if ($posts) $cms['post_collections'] = $posts;
    return ['data' => $data, 'model' => $model, 'cms_settings' => $cms];
}

function editorFormat1Field(array $def): array
{
    $type = (string) ($def['type'] ?? 'text');
    $out = ['name' => (string) ($def['name'] ?? '')];
    if ($type === 'list') {
        $out['type'] = 'array';
        $out['of'] = array_map('editorFormat1Field', array_values(array_filter((array) ($def['of'] ?? []), 'is_array')));
    } else {
        $out['type'] = match ($type) { 'text' => empty($def['localized']) ? 'plain' : 'text', 'media' => 'image', default => $type };
        if (in_array($type, ['boolean', 'select'], true) && !empty($def['localized'])) $out['localized'] = true;
    }
    foreach (['description', 'section', 'options'] as $key) {
        if (isset($def[$key])) $out[$key] = $def[$key];
    }
    if ($type === 'select' && !empty($def['multiple'])) $out['multiple'] = true;
    return $out;
}

/** I valori per nome come lista {name, type, value} della 1.x: prima i campi dello schema, poi gli altri. */
function editorFormat1Data(array $values, array $fields, array $langs): array
{
    $defs = array_column(array_filter($fields, 'is_array'), null, 'name');
    $out = [];
    foreach (array_merge(array_keys($defs), array_keys($values)) as $name) {
        if (!array_key_exists($name, $values) || isset($out[$name])) continue;
        $value = $values[$name];
        $def = $defs[$name] ?? ['type' => is_array($value) && array_is_list($value) ? 'list' : (is_bool($value) ? 'boolean' : 'text'),
            'localized' => is_array($value) && !array_is_list($value)];
        $out[$name] = ['name' => (string) $name, 'type' => editorFormat1Field(['name' => $name] + $def)['type'], 'value' => editorFormat1Value($def, $value, $langs)];
    }
    return array_values($out);
}

function editorFormat1Value(array $def, mixed $value, array $langs): mixed
{
    $type = (string) ($def['type'] ?? 'text');
    if ($type === 'list') {
        $items = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (!is_array($item)) continue;
            $items[] = ['id' => (int) ($item['id'] ?? 0)] + (!empty($item['hidden']) ? ['hidden' => true] : [])
                + ['data' => editorFormat1Data((array) ($item['values'] ?? []), (array) ($def['of'] ?? []), $langs)];
        }
        return $items;
    }
    $one = fn(mixed $v): string => match ($type) {
        'boolean' => $v === true || $v === '1' ? '1' : '',
        'select' => is_array($v) ? implode(',', array_map('strval', $v)) : (string) $v,
        default => is_scalar($v) ? (string) $v : '',
    };
    $perLanguage = in_array($type, ['boolean', 'select'], true) ? !empty($def['localized']) : !($type === 'text' && empty($def['localized']));
    if (is_array($value) && !array_is_list($value)) {
        $map = array_map($one, $value);
        if ($perLanguage) return $map;
        foreach ($langs as $lang) {
            if (($map[$lang] ?? '') !== '') return $map[$lang];
        }
        return (string) (array_values(array_filter($map, fn($v) => $v !== ''))[0] ?? '');
    }
    // Un valore condiviso in un campo che l'editor mostra per lingua (media, richtext): lo stesso in ogni lingua
    return $perLanguage ? array_fill_keys($langs, $one($value)) : $one($value);
}

// --- dall'editor al formato 2 -------------------------------------------------

/** I campi della 1.x nel formato 2, tenendo dei campi esistenti quello che l'editor non mostra (media o richtext condivisi). */
function editorFormat2Fields(array $fields, array $old): array
{
    $before = array_column(array_filter($old, 'is_array'), null, 'name');
    $out = [];
    foreach ($fields as $f) {
        if (!is_array($f) || !is_string($f['name'] ?? null)) continue;
        $prev = $before[$f['name']] ?? [];
        $t1 = (string) ($f['type'] ?? 'text');
        $type = match ($t1) { 'plain' => 'text', 'image' => 'media', 'array' => 'list', default => $t1 };
        $def = ['name' => $f['name'], 'type' => $type];
        if ($type === 'list') {
            $def['of'] = editorFormat2Fields((array) ($f['of'] ?? []), (array) ($prev['of'] ?? []));
        } else {
            $def['localized'] = match (true) {
                $t1 === 'plain' => false,
                in_array($type, ['boolean', 'select'], true) => !empty($f['localized']),
                $t1 === 'text' => true,
                default => ($prev['type'] ?? null) === $type ? !empty($prev['localized']) : true,
            };
        }
        foreach (['description', 'section', 'options'] as $key) {
            if (isset($f[$key])) $def[$key] = $f[$key];
        }
        if ($type === 'select') $def['multiple'] = !empty($f['multiple']);
        $out[] = $def;
    }
    return $out;
}

/** Le definizioni del formato 2 dei campi a un percorso (['servizi', 2] → i campi degli elementi di servizi). */
function editorFields2(array $schema, string $type, array $path): array
{
    $fields = (array) ($schema['types'][$type]['fields'] ?? []);
    for ($i = 0; $i < count($path); $i += 2) {
        $def = array_column(array_filter($fields, 'is_array'), null, 'name')[(string) $path[$i]] ?? null;
        $fields = is_array($def) ? (array) ($def['of'] ?? []) : [];
    }
    return $fields;
}

/** Una lista {name, type, value} della 1.x come valori per nome del formato 2. */
function editorFormat2Values(array $data, array $fields, array $langs): array
{
    $defs = array_column(array_filter($fields, 'is_array'), null, 'name');
    $values = [];
    foreach ($data as $f) {
        if (!is_array($f) || !is_string($f['name'] ?? null) || in_array($f['name'], ['id', 'language'], true)) continue;
        $def = $defs[$f['name']] ?? editorFormat2Fields([['name' => $f['name'], 'type' => (string) ($f['type'] ?? 'text')]], [])[0];
        $values[$f['name']] = editorFormat2Value($def, $f['value'] ?? null, $langs);
    }
    return $values;
}

function editorFormat2Value(array $def, mixed $value, array $langs): mixed
{
    $type = (string) ($def['type'] ?? 'text');
    if ($type === 'list') {
        $items = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (!is_array($item)) continue;
            $items[] = ['id' => (int) ($item['id'] ?? 0)] + (!empty($item['hidden']) ? ['hidden' => true] : [])
                + ['values' => editorFormat2Values((array) ($item['data'] ?? []), (array) ($def['of'] ?? []), $langs)];
        }
        return $items;
    }
    $one = fn(mixed $v): mixed => match ($type) {
        'boolean' => in_array($v, [true, 1, '1', 'true', 'on'], true),
        'select' => !empty($def['multiple'])
            ? array_values(array_filter(array_map('trim', is_array($v) ? array_map('strval', $v) : explode(',', (string) $v)), fn($s) => $s !== ''))
            : (string) (is_array($v) ? ($v[0] ?? '') : ($v ?? '')),
        // Il testo formattato si salva filtrato (Kris\Template\Richtext)
        'richtext' => is_scalar($v) ? \Kris\Template\Richtext::clean((string) $v) : '',
        default => is_scalar($v) ? (string) $v : '',
    };
    if (empty($def['localized'])) {
        if (is_array($value) && !array_is_list($value)) {
            $first = null;
            foreach (array_merge(array_intersect_key($value, array_flip($langs)), $value) as $v) {
                if ($v !== null && $v !== '') { $first = $v; break; }
            }
            $value = $first ?? '';
        }
        return $one($value);
    }
    if (!is_array($value) || array_is_list($value)) return [$langs[0] => $one($value)];
    return array_map($one, $value);
}

/**
 * Salva un documento della 1.x scritto dall'editor nei documenti del
 * formato 2, in una transazione. Un sito ancora nel formato 1 riceve tutti e
 * tre i documenti, convertiti.
 */
function editorSaveFormat1(string $doc, array $value, ?string $root = null): void
{
    $root ??= KRIS_ROOT;
    $store = \Kris\Store\Stores::site($root);
    $store->transaction(['schema', 'content', 'settings'], function (array $current) use ($doc, $value, $store, $root): array {
        $legacy = $store->read('schema')->rev === '' || $current['schema'] === [];
        $site = $legacy ? \Kris\Store\Site::current($root) : $current;
        $langs = is_array($site['settings']['languages'] ?? null) && $site['settings']['languages'] ? array_values($site['settings']['languages']) : ['it', 'en'];
        $site['schema'] += ['format' => 2, 'types' => [], 'pages' => []];
        if ($doc === 'model') {
            $types = [];
            foreach ($value as $type => $fields) {
                $prev = is_array($site['schema']['types'][$type] ?? null) ? $site['schema']['types'][$type] : [];
                $types[$type] = array_diff_key($prev, ['fields' => 1]) + ['fields' => editorFormat2Fields((array) $fields, (array) ($prev['fields'] ?? []))];
            }
            $site['schema']['types'] = $types;
        } elseif ($doc === 'data') {
            $content = [];
            foreach ($value as $entity) {
                if (!is_array($entity)) continue;
                $type = (string) ($entity['name'] ?? '');
                $content[] = ['type' => $type, 'id' => (int) ($entity['id'] ?? 0)] + (!empty($entity['hidden']) ? ['hidden' => true] : [])
                    + ['values' => editorFormat2Values((array) ($entity['data'] ?? []), (array) ($site['schema']['types'][$type]['fields'] ?? []), $langs)];
            }
            $site['content'] = $content;
        } else {
            // cms_settings: lingue e caricamenti nelle impostazioni, nome, Posts e ordine delle raccolte nello schema
            $info = is_array($value['collections'] ?? null) ? $value['collections'] : [];
            $posts = is_array($value['post_collections'] ?? null) ? $value['post_collections'] : [];
            $types = [];
            foreach ($site['schema']['types'] as $type => $t) {
                $t = array_diff_key($t, ['label' => 1, 'description' => 1, 'single' => 1, 'newest_first' => 1]);
                $attrs = array_intersect_key(is_array($info[$type] ?? null) ? $info[$type] : [], ['label' => 1, 'description' => 1, 'single' => 1]);
                if (in_array($type, $posts, true)) $attrs['newest_first'] = true;
                $types[$type] = $attrs + $t;
            }
            $order = is_array($value['collection_order'] ?? null) ? array_values(array_filter($value['collection_order'], fn($n) => isset($types[$n]))) : [];
            $sorted = [];
            foreach ($order as $name) $sorted[$name] = $types[$name];
            $site['schema']['types'] = $sorted + $types;
            $site['settings'] = array_diff_key($value, ['collections' => 1, 'collection_order' => 1, 'post_collections' => 1]);
        }
        if ($legacy) return $site;
        return array_filter($site, fn($v, $k) => $v !== $current[$k], ARRAY_FILTER_USE_BOTH);
    });
}
