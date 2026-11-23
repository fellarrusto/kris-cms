<?php
// Formato 2 dei dati (docs/2.0/specifica.md, 2). Dai cinque documenti della
// 1.x (k_data, k_model, cms_settings, allowed_pages, seo) a tre:
//  - schema: tipi con i loro campi e attributi, pagine, elenchi promossi,
//    reindirizzamenti;
//  - content: i contenuti nell'ordine, con i valori per nome;
//  - settings: lingue, caricamenti, opzioni del sito non editoriali.
// Il tipo di un campo sta solo nello schema. plain diventa text non
// localizzato, image diventa media (per lingua, come prima), array diventa
// list, boolean vale true/false, una scelta multipla è una lista.
//
// Un elenco di primo livello con una pagina di dettaglio (regola della
// sitemap con children, link path=elenco/… nei template) o mostrato in Posts
// diventa un tipo: gli elementi diventano contenuti con lo stesso id e
// "promoted" ricorda da dove venivano, per i vecchi indirizzi. Solo se il
// tipo che lo contiene ha un solo contenuto: altrimenti resta un elenco.
//
// Riceve anche allowed_pages, seo e templates (nome => HTML, sola lettura).
// Già nel formato 2 (c'è schema): non cambia niente.
return [
    'version'     => 9,
    'description' => 'Formato 2 dei dati: schema, contenuti e impostazioni, valori per nome, elenchi con pagina promossi a tipo.',
    'auto'        => false,
    'up'          => static function (array $files): array {
        if (isset($files['schema'])) return $files;

        $model = is_array($files['k_model'] ?? null) ? $files['k_model'] : [];
        $data = is_array($files['k_data'] ?? null) ? array_values(array_filter($files['k_data'], 'is_array')) : [];
        $cms = is_array($files['cms_settings'] ?? null) ? $files['cms_settings'] : [];
        $seo = is_array($files['seo'] ?? null) ? $files['seo'] : [];
        $templates = is_array($files['templates'] ?? null) ? $files['templates'] : [];
        $langs = is_array($cms['languages'] ?? null) && $cms['languages'] ? array_values($cms['languages']) : ['it', 'en'];
        $allowed = $files['allowed_pages']['allowed_pages'] ?? ['homepage'];
        $allowed = array_values(array_filter(is_array($allowed) ? $allowed : ['homepage'], 'is_string'));
        $sitemap = is_array($seo['sitemap'] ?? null) ? array_values(array_filter($seo['sitemap'], 'is_array')) : [['page' => 'homepage']];

        // Campi: il tipo del formato 2 e l'attributo localized
        $field = static function (array $f) use (&$field): array {
            $type = (string) ($f['type'] ?? 'text');
            $out = ['name' => (string) ($f['name'] ?? '')];
            if ($type === 'array') {
                $out['type'] = 'list';
                $out['of'] = array_map($field, array_values(array_filter(is_array($f['of'] ?? null) ? $f['of'] : [], 'is_array')));
            } else {
                $out['type'] = match ($type) { 'plain' => 'text', 'image' => 'media', default => $type };
                $out['localized'] = match ($type) { 'plain' => false, 'boolean', 'select' => !empty($f['localized']), default => true };
            }
            foreach (['description', 'section', 'options'] as $key) {
                if (isset($f[$key])) $out[$key] = $f[$key];
            }
            if ($type === 'select') $out['multiple'] = !empty($f['multiple']);
            return $out;
        };

        // Valori: per nome, secondo la definizione del campo (o il tipo salvato nel dato)
        $value = static function (array $def, mixed $v) use (&$value, $langs): mixed {
            $one = static function (array $def, mixed $v): mixed {
                return match ($def['type']) {
                    'boolean' => in_array($v, [true, 1, '1', 'true', 'on'], true),
                    'select' => !empty($def['multiple'])
                        ? array_values(array_filter(array_map('trim', is_array($v) ? array_map('strval', $v) : explode(',', (string) $v)), fn($s) => $s !== ''))
                        : (string) (is_array($v) ? ($v[0] ?? '') : ($v ?? '')),
                    default => is_scalar($v) || $v === null ? (string) $v : '',
                };
            };
            if ($def['type'] === 'list') {
                $items = [];
                foreach (is_array($v) ? $v : [] as $child) {
                    if (!is_array($child)) continue;
                    $items[] = (isset($child['id']) ? ['id' => (int) $child['id']] : []) + (($child['hidden'] ?? false) === true ? ['hidden' => true] : [])
                        + ['values' => ($value)(['type' => 'item', 'of' => $def['of'] ?? []], $child['data'] ?? [])];
                }
                return $items;
            }
            if ($def['type'] === 'item') {
                $defs = array_column($def['of'], null, 'name');
                $values = [];
                foreach (is_array($v) ? $v : [] as $f) {
                    if (!is_array($f) || !is_string($f['name'] ?? null) || in_array($f['name'], ['id', 'language'], true)) continue;
                    // Un dato senza campo nello schema resta, con il tipo che aveva
                    $d = $defs[$f['name']] ?? ['type' => match ((string) ($f['type'] ?? 'text')) {
                        'plain' => 'text', 'image' => 'media', 'array' => 'list', default => (string) ($f['type'] ?? 'text'),
                    }, 'localized' => is_array($f['value'] ?? null) && !array_is_list($f['value'])];
                    $values[$f['name']] = ($value)($d, $f['value'] ?? null);
                }
                return $values;
            }
            if (empty($def['localized'])) {
                // Un valore per lingua su un campo condiviso: il primo non vuoto
                if (is_array($v) && !array_is_list($v)) {
                    $first = '';
                    foreach ($v as $x) {
                        if ($x !== null && $x !== '') { $first = $x; break; }
                    }
                    $v = $first;
                }
                return $one($def, $v);
            }
            if (!is_array($v) || array_is_list($v)) {
                // Un valore solo su un campo per lingua: vale per la lingua principale
                return [$langs[0] => $one($def, $v)];
            }
            $out = [];
            foreach ($v as $lang => $x) $out[(string) $lang] = $one($def, $x);
            return $out;
        };

        // Collegamenti ai dettagli nei template: page, key e primo elenco del path
        $links = [];
        foreach ($templates as $html) {
            if (!is_string($html) || !preg_match_all('/[?&;]page=([a-z0-9][a-z0-9_-]*)[^"\'\s<>]*/i', $html, $m, PREG_SET_ORDER)) continue;
            foreach ($m as $match) {
                $query = str_replace('&amp;', '&', $match[0]);
                $key = preg_match('/[?&;]key=([a-z0-9_]+)(?=[&#]|$)/i', $query, $k) === 1 ? $k[1] : 'homepage';
                $list = preg_match('/[?&;]path=([a-z0-9_]+)\//i', $query, $p) === 1 ? $p[1] : null;
                $id = preg_match('/[?&;]id=([^&#]*)/i', $query, $i) === 1 ? $i[1] : null;
                $links[] = ['page' => $match[1], 'key' => $key, 'list' => $list, 'id' => $id];
            }
        }

        // Elenchi da promuovere: (tipo, elenco) => nome del tipo nuovo
        $count = [];
        foreach ($data as $entity) $count[(string) ($entity['name'] ?? '')] = ($count[(string) ($entity['name'] ?? '')] ?? 0) + 1;
        $wanted = [];
        foreach ($sitemap as $rule) {
            if (is_string($rule['children'] ?? null) && $rule['children'] !== '') $wanted[(is_string($rule['key'] ?? null) ? $rule['key'] : 'homepage') . '/' . $rule['children']] = true;
        }
        foreach ($links as $link) {
            if ($link['list'] !== null) $wanted[$link['key'] . '/' . $link['list']] = true;
        }
        foreach ($model as $type => $fields) {
            foreach (is_array($fields) ? $fields : [] as $f) {
                if (is_array($f) && ($f['type'] ?? null) === 'array' && !empty($f['posts'])) $wanted["{$type}/{$f['name']}"] = true;
            }
        }
        $promoted = [];
        foreach (array_keys($wanted) as $pair) {
            [$type, $list] = explode('/', $pair, 2);
            $def = null;
            foreach (is_array($model[$type] ?? null) ? $model[$type] : [] as $f) {
                if (is_array($f) && ($f['name'] ?? null) === $list && ($f['type'] ?? null) === 'array') $def = $f;
            }
            if ($def === null || ($count[$type] ?? 0) > 1) continue;
            $name = $list;
            for ($n = 2; isset($model[$name]) || str_starts_with($name, 'kris_') || in_array($name, $promoted, true); $n++) {
                $name = "{$type}_{$list}" . ($n > 2 ? "_{$n}" : '');
            }
            $promoted[$pair] = $name;
        }

        // Tipi, nell'ordine dell'editor
        $info = is_array($cms['collections'] ?? null) ? $cms['collections'] : [];
        $order = is_array($cms['collection_order'] ?? null) ? array_values($cms['collection_order']) : [];
        $posts = is_array($cms['post_collections'] ?? null) ? $cms['post_collections'] : [];
        $names = array_keys($model);
        usort($names, function ($a, $b) use ($order, $names) {
            $pa = array_search($a, $order, true);
            $pb = array_search($b, $order, true);
            if ($pa !== false && $pb !== false) return $pa <=> $pb;
            if ($pa !== false || $pb !== false) return $pa !== false ? -1 : 1;
            return array_search($a, $names, true) <=> array_search($b, $names, true);
        });
        $types = [];
        foreach ($names as $type) {
            $fields = [];
            foreach (is_array($model[$type]) ? $model[$type] : [] as $f) {
                if (!is_array($f) || !is_string($f['name'] ?? null)) continue;
                if (isset($promoted["{$type}/{$f['name']}"])) continue;
                $fields[] = $field($f);
            }
            $attrs = is_array($info[$type] ?? null) ? $info[$type] : [];
            $types[$type] = array_filter([
                'label' => is_string($attrs['label'] ?? null) && $attrs['label'] !== '' ? $attrs['label'] : null,
                'description' => is_string($attrs['description'] ?? null) && $attrs['description'] !== '' ? $attrs['description'] : null,
                'single' => !empty($attrs['single']) ? true : null,
                'newest_first' => in_array($type, $posts, true) ? true : null,
                'reserved' => str_starts_with((string) $type, 'kris_') ? true : null,
            ], fn($v) => $v !== null) + ['fields' => $fields];
            foreach ($promoted as $pair => $name) {
                [$from, $list] = explode('/', $pair, 2);
                if ($from !== $type) continue;
                foreach ($model[$type] as $f) {
                    if (!is_array($f) || ($f['name'] ?? null) !== $list) continue;
                    // Una descrizione breve è un buon nome visibile; una frase lunga resta descrizione
                    $text = is_string($f['description'] ?? null) ? trim($f['description']) : '';
                    $short = $text !== '' && mb_strlen($text) <= 40 && !preg_match('/[.:;!?]\s*$/u', $text);
                    $types[$name] = array_filter([
                        'label' => $short ? $text : ($text !== '' ? ucfirst(str_replace('_', ' ', $name)) : null),
                        'description' => !$short && $text !== '' ? $text : null,
                        'newest_first' => !empty($f['posts']) ? true : null,
                    ], fn($v) => $v !== null) + ['fields' => array_map($field, array_values(array_filter(is_array($f['of'] ?? null) ? $f['of'] : [], 'is_array')))];
                }
            }
        }

        // Contenuti, con gli elementi promossi subito dopo il contenuto da cui vengono
        $content = [];
        $from = [];
        foreach ($data as $entity) {
            $type = (string) ($entity['name'] ?? '');
            $defs = [];
            foreach (is_array($model[$type] ?? null) ? $model[$type] : [] as $f) {
                if (is_array($f) && is_string($f['name'] ?? null)) $defs[] = $field($f);
            }
            $values = $value(['type' => 'item', 'of' => $defs], $entity['data'] ?? []);
            $children = [];
            foreach ($promoted as $pair => $name) {
                [$t, $list] = explode('/', $pair, 2);
                if ($t !== $type) continue;
                foreach (is_array($values[$list] ?? null) ? $values[$list] : [] as $child) {
                    $children[] = ['type' => $name] + $child;
                }
                unset($values[$list]);
                $from[] = ['from' => $type, 'id' => (int) ($entity['id'] ?? 0), 'list' => $list, 'to' => $name];
            }
            $content[] = ['type' => $type, 'id' => (int) ($entity['id'] ?? 0)] + (($entity['hidden'] ?? false) === true ? ['hidden' => true] : []) + ['values' => $values];
            array_push($content, ...$children);
        }
        // Un elenco promosso di un tipo senza contenuti: il tipo c'è, la provenienza no
        foreach ($promoted as $pair => $name) {
            [$t, $list] = explode('/', $pair, 2);
            if (!in_array($pair, array_map(fn($p) => "{$p['from']}/{$p['list']}", $from), true)) $from[] = ['from' => $t, 'id' => 0, 'list' => $list, 'to' => $name];
        }

        // Pagine: dalle regole della sitemap, altrimenti dai link dei template, altrimenti dal nome
        $pages = [];
        foreach ($allowed as $page) {
            $rule = null;
            foreach ($sitemap as $r) {
                if (($r['page'] ?? null) === $page) { $rule = $r; break; }
            }
            $entry = ['template' => $page];
            if ($rule !== null) {
                $key = is_string($rule['key'] ?? null) ? $rule['key'] : 'homepage';
                $list = is_string($rule['children'] ?? null) && $rule['children'] !== '' ? $rule['children'] : null;
                if ($list !== null && isset($promoted["{$key}/{$list}"])) {
                    $entry['type'] = $promoted["{$key}/{$list}"];
                } elseif ($list !== null) {
                    $entry += ['type' => $key, 'item' => (int) ($rule['id'] ?? 0), 'list' => $list];
                } elseif (array_key_exists('id', $rule) || $key === 'homepage') {
                    $entry += ['type' => $key, 'item' => (int) ($rule['id'] ?? 0)];
                } else {
                    $entry['type'] = $key;
                }
                $entry['sitemap'] = true;
                if (isset($rule['priority']) && is_numeric($rule['priority'])) $entry['priority'] = (float) $rule['priority'];
                if (is_string($rule['slug'] ?? null) && $rule['slug'] !== '' && !isset($entry['item'])) $entry['slug'] = $rule['slug'];
            } else {
                $link = null;
                foreach ($links as $l) {
                    if ($l['page'] === $page) { $link = $l; break; }
                }
                if ($link !== null && $link['list'] !== null && isset($promoted["{$link['key']}/{$link['list']}"])) {
                    $entry['type'] = $promoted["{$link['key']}/{$link['list']}"];
                } elseif ($link !== null && isset($model[$link['key']])) {
                    $entry['type'] = $link['key'];
                    if ($link['list'] !== null) {
                        $entry += ['item' => ctype_digit((string) $link['id']) ? (int) $link['id'] : 0, 'list' => $link['list']];
                    } elseif ($link['id'] === null || ctype_digit($link['id'])) {
                        $entry['item'] = (int) ($link['id'] ?? 0);
                    }
                } else {
                    $entry += ['type' => isset($model[$page]) ? $page : 'homepage', 'item' => 0];
                }
                $entry['sitemap'] = false;
            }
            $pages[$page] = $entry;
        }

        $schema = ['format' => 2, 'types' => $types, 'pages' => $pages];
        if ($from) $schema['promoted'] = $from;
        if (is_array($seo['redirects'] ?? null) && $seo['redirects']) $schema['redirects'] = $seo['redirects'];

        $settings = array_diff_key($cms, array_flip(['collections', 'collection_order', 'post_collections']));
        $settings['languages'] = $langs;
        $site = array_intersect_key($seo, array_flip(['base_url', 'head', 'pretty_urls', 'auto_language', 'robots']));
        if ($site) $settings['seo'] = $site;

        return ['schema' => $schema, 'content' => $content, 'settings' => $settings];
    },
];
