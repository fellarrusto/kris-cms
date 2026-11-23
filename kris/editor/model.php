<?php
declare(strict_types=1);
if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

use Kris\Staging\Live;
use Kris\Staging\Staging;

/**
 * Il formato 2 come lo usa l'editor (docs/2.0/specifica.md, 2): lo stato del
 * sito, o dello staging in «In attesa», e le funzioni per trovare tipi,
 * contenuti, elementi e campi. Le scritture sono operazioni (Operations):
 * subito sul sito con Live, registrate con Staging in attesa.
 */

/** Lo stato su cui lavora la pagina; con $state lo imposta (index.php e dopo ogni scrittura). */
function editorSite(?array $state = null): array
{
    static $site = ['schema' => ['types' => [], 'pages' => []], 'content' => [], 'settings' => []];
    if ($state !== null) {
        $schema = is_array($state['schema'] ?? null) ? $state['schema'] : [];
        $schema['types'] = is_array($schema['types'] ?? null) ? $schema['types'] : [];
        $schema['pages'] = is_array($schema['pages'] ?? null) ? $schema['pages'] : [];
        $site = [
            'schema' => $schema,
            'content' => array_values(array_filter(is_array($state['content'] ?? null) ? $state['content'] : [], 'is_array')),
            'settings' => is_array($state['settings'] ?? null) ? $state['settings'] : [],
        ];
    }
    return $site;
}

/** Rilegge lo stato della modalità in uso: il sito o le modifiche in attesa. */
function editorReload(bool $staging): void
{
    $state = $staging ? (new Staging())->state() : \Kris\Store\Site::current(KRIS_ROOT);
    // Lingue e caricamenti valgono sempre quelli del sito pubblico
    if ($staging) $state['settings'] = \Kris\Store\Site::current(KRIS_ROOT)['settings'];
    editorSite($state);
}

/**
 * Applica operazioni nella modalità in uso: tutte o nessuna.
 *
 * @return list<array> le operazioni completate
 * @throws \Kris\Staging\StagingException
 */
function editorApply(array $ops, bool $staging): array
{
    if (!$ops) return [];
    $by = 'editor: ' . (string) ($_SESSION['kris_user'] ?? '');
    if ($staging) {
        $target = new Staging();
        $done = $target->operateAll($ops, $by);
        editorWarnings($target->lastWarnings());
    } else {
        $done = Live::apply(KRIS_ROOT, $ops);
        editorWarnings(Live::lastWarnings());
    }
    editorReload($staging);
    return $done;
}

/** Gli avvisi dell'ultima scrittura (lingue rimaste vuote); con $set li registra. */
function editorWarnings(?array $set = null): array
{
    static $warnings = [];
    if ($set !== null) $warnings = $set;
    return $warnings;
}

function editorLanguages(): array
{
    $langs = editorSite()['settings']['languages'] ?? null;
    return is_array($langs) && $langs ? array_values(array_map('strval', $langs)) : ['it', 'en'];
}

/** Tipi di Kris (kris_) e dei plugin (plg_): schema fisso, sezioni dedicate. */
function isReservedCollection(mixed $name): bool
{
    return is_string($name) && (str_starts_with($name, 'kris_') || str_starts_with($name, 'plg_'));
}

function editorType(string $name): ?array
{
    $type = editorSite()['schema']['types'][$name] ?? null;
    return is_array($type) ? $type : null;
}

/** I tipi del sito nell'ordine dell'editor, senza quelli riservati. */
function editorUserTypes(): array
{
    $out = [];
    foreach (editorSite()['schema']['types'] as $name => $type) {
        if (is_array($type) && !isReservedCollection((string) $name) && empty($type['reserved'])) $out[(string) $name] = $type;
    }
    return $out;
}

function editorLabel(string $name): string
{
    return ucfirst(str_replace('_', ' ', $name));
}

function collectionLabel(string $name): string
{
    $label = editorType($name)['label'] ?? '';
    return is_string($label) && trim($label) !== '' ? trim($label) : editorLabel($name);
}

function collectionDescription(string $name): string
{
    $description = editorType($name)['description'] ?? '';
    return is_string($description) ? trim($description) : '';
}

/** Un contenuto unico (una pagina, gli elementi comuni): si apre direttamente la modifica. */
function isSingleCollection(string $name): bool
{
    return (editorType($name)['single'] ?? false) === true;
}

/** Una raccolta di articoli (Posts): i nuovi in cima. */
function isNewestFirst(string $name): bool
{
    return (editorType($name)['newest_first'] ?? false) === true;
}

/** @return list<array> i contenuti del tipo, nell'ordine */
function editorItems(string $type): array
{
    return array_values(array_filter(editorSite()['content'], fn($item) => ($item['type'] ?? null) === $type));
}

function editorItem(string $type, int $id): ?array
{
    foreach (editorSite()['content'] as $item) {
        if (($item['type'] ?? null) === $type && (int) ($item['id'] ?? -1) === $id) return $item;
    }
    return null;
}

function isHiddenItem(array $item): bool
{
    return ($item['hidden'] ?? false) === true;
}

/**
 * Un contenuto o un elemento dei suoi elenchi: $path alterna nome
 * dell'elenco e id dell'elemento (['servizi', 2, 'punti', 0]).
 */
function editorNode(string $type, int $id, array $path): ?array
{
    $node = editorItem($type, $id);
    for ($i = 0, $n = count($path); $node !== null && $i < $n; $i += 2) {
        if (!isset($path[$i + 1])) return null;
        $found = null;
        $children = $node['values'][(string) $path[$i]] ?? null;
        foreach (is_array($children) ? $children : [] as $child) {
            if (is_array($child) && (int) ($child['id'] ?? -1) === (int) $path[$i + 1]) $found = $child;
        }
        $node = $found;
    }
    return $node;
}

/** I nomi degli elenchi di un percorso: ['servizi', 2, 'punti'] → ['servizi', 'punti']. */
function editorListNames(array $path): array
{
    $out = [];
    for ($i = 0, $n = count($path); $i < $n; $i += 2) $out[] = (string) $path[$i];
    return $out;
}

/** I campi a un livello: quelli del tipo, o quelli degli elementi degli elenchi nominati. */
function editorFields(string $type, array $listNames = []): array
{
    $fields = editorType($type)['fields'] ?? [];
    foreach ($listNames as $list) {
        $def = editorFieldDef(is_array($fields) ? $fields : [], $list);
        if (($def['type'] ?? null) !== 'list') return [];
        $fields = $def['of'] ?? [];
    }
    return array_values(array_filter(is_array($fields) ? $fields : [], fn($f) => is_array($f) && is_string($f['name'] ?? null)));
}

function editorFieldDef(array $fields, string $name): ?array
{
    foreach ($fields as $field) {
        if (is_array($field) && ($field['name'] ?? null) === $name) return $field;
    }
    return null;
}

/** Il nome che l'editor mostra per un campo: la descrizione, o il nome tecnico leggibile. */
function editorFieldLabel(array $def): string
{
    $label = trim((string) ($def['description'] ?? ''));
    return $label !== '' ? $label : editorLabel((string) ($def['name'] ?? ''));
}

/** Il testo di un valore nella lingua (o il primo non vuoto), '' se non è testo. */
function editorText(mixed $value, ?string $lang = null): string
{
    if (is_array($value)) {
        if (array_is_list($value)) return '';
        $lang ??= editorLanguages()[0];
        $value = is_string($value[$lang] ?? null) && trim((string) $value[$lang]) !== '' ? $value[$lang]
            : (array_values(array_filter($value, fn($v) => is_string($v) && trim($v) !== ''))[0] ?? '');
    }
    return is_string($value) ? trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
}

/** Titolo leggibile di un contenuto o di un elemento. */
function editorTitle(?array $node, array $fields = []): string
{
    if ($node === null) return 'Contenuto non disponibile';
    $values = is_array($node['values'] ?? null) ? $node['values'] : [];
    $types = array_column($fields, 'type', 'name');
    foreach (['title', 'page_title', 'titolo', 'name', 'nome', 'meta_title', null] as $preferred) {
        foreach ($values as $name => $value) {
            if ($preferred !== null && $name !== $preferred) continue;
            if (isset($types[$name]) && !in_array($types[$name], ['text', 'richtext'], true)) continue;
            $text = editorText($value);
            if ($text !== '') return mb_substr($text, 0, 85);
        }
    }
    return 'Contenuto #' . (int) ($node['id'] ?? 0);
}

// --- Struttura ---------------------------------------------------------------

/** Tipi di campo, con il nome che vede chi modifica la struttura. */
const EDITOR_FIELD_TYPES = [
    'text' => 'Testo',
    'richtext' => 'Testo formattato',
    'media' => 'Immagine o file',
    'boolean' => 'Interruttore',
    'select' => 'Scelta',
    'list' => 'Elenco',
    'embed' => 'Contenuto incorporato',
];

/**
 * I campi divisi per sezione, nell'ordine in cui le sezioni compaiono:
 * [['section' => '' | nome, 'fields' => [indice => campo]]]. I campi senza
 * sezione stanno in testa.
 */
function schemaSections(array $schema): array
{
    $sections = ['' => []];
    foreach ($schema as $i => $f) {
        $sections[is_string($f['section'] ?? null) ? $f['section'] : ''][$i] = $f;
    }
    $out = [];
    foreach ($sections as $name => $fields) {
        if ($fields) $out[] = ['section' => (string) $name, 'fields' => $fields];
    }
    return $out;
}

// Nomi che il modulo usa per i propri controlli: un campo con lo stesso nome li sovrascriverebbe
const RESERVED_FIELD_NAMES = ['id', 'language', 'group', 'path', 'save_entity', 'create_instance',
    'create_nested', 'delete_nested', 'delete_instance', 'reorder_root', 'reorder_nested',
    'save_structure', 'delete_collection', 'save_settings', 'delete_media', 'file_name',
    'order', 'group_name', 'schema_json', 'collection_name', 'langs', 'f', 'return_posts'];

/**
 * Pulisce i campi mandati dalla Struttura: solo le chiavi del formato 2,
 * sezioni ripulite (solo al primo livello).
 */
function editorCleanSchema(array $fields, bool $root = true): array
{
    $out = [];
    foreach ($fields as $f) {
        if (!is_array($f)) continue;
        $type = (string) ($f['type'] ?? '');
        $clean = ['name' => trim((string) ($f['name'] ?? '')), 'type' => $type];
        if ($type === 'list') {
            $clean['of'] = editorCleanSchema(is_array($f['of'] ?? null) ? $f['of'] : [], false);
        } else {
            $clean['localized'] = !empty($f['localized']);
        }
        if ($type === 'select') {
            $clean['options'] = array_values(array_unique(array_filter(array_map(fn($o) => trim((string) $o), (array) ($f['options'] ?? [])), fn($o) => $o !== '')));
            $clean['multiple'] = !empty($f['multiple']);
        }
        $description = trim((string) ($f['description'] ?? ''));
        if ($description !== '') $clean['description'] = mb_substr($description, 0, 300);
        $section = $root && is_string($f['section'] ?? null) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $f['section'])), 0, 60) : '';
        if ($section !== '') $clean['section'] = $section;
        $out[] = $clean;
    }
    return $out;
}

/** I problemi di uno schema prima di salvarlo: nomi, tipi, duplicati, opzioni. */
function validateSchema(array $schema, string $where = ''): array
{
    $problems = [];
    $seen = [];
    foreach ($schema as $f) {
        $name = (string) ($f['name'] ?? '');
        $label = $where === '' ? "«{$name}»" : "«{$where} / {$name}»";
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $problems[] = "il nome {$label} non è valido: lettere minuscole, numeri e _, iniziando con una lettera.";
            continue;
        }
        if (in_array($name, RESERVED_FIELD_NAMES, true)) $problems[] = "«{$name}» è un nome riservato: usane un altro (es. «{$name}_campo»).";
        if (isset($seen[$name])) $problems[] = "il campo {$label} è presente due volte.";
        $seen[$name] = true;
        if (!isset(EDITOR_FIELD_TYPES[$f['type'] ?? ''])) $problems[] = "il tipo del campo {$label} non è riconosciuto.";
        if (($f['type'] ?? '') === 'select') {
            if (!$f['options']) $problems[] = "la scelta {$label} non ha opzioni (una per riga).";
            foreach ($f['options'] as $option) {
                if (str_contains($option, ',')) $problems[] = "l'opzione «{$option}» di {$label} non può contenere virgole.";
            }
        }
        if (($f['type'] ?? '') === 'list') $problems = array_merge($problems, validateSchema($f['of'], $where === '' ? $name : "{$where} / {$name}"));
    }
    return $problems;
}

/** Due definizioni dello stesso campo hanno lo stesso tipo (e per lingua o no)? */
function editorSameKind(array $a, array $b): bool
{
    return $a['type'] === $b['type'] && ($a['type'] === 'list' || !empty($a['localized']) === !empty($b['localized']));
}

/**
 * Le operazioni che portano i campi di un tipo da $old a $new: tolti, aggiunti,
 * cambiati di tipo (con la conversione dei valori), descrizione, sezione,
 * opzioni, ordine. Un campo rinominato è un campo tolto e uno aggiunto.
 */
function editorSchemaOps(string $type, array $old, array $new, array $path = []): array
{
    $ops = [];
    $oldByName = array_column($old, null, 'name');
    $newByName = array_column($new, null, 'name');
    $base = ['collection' => $type] + ($path ? ['path' => $path] : []);
    // Tolti
    foreach ($old as $f) {
        if (!isset($newByName[$f['name']])) $ops[] = ['op' => 'remove_field', 'name' => $f['name']] + $base;
    }
    $current = array_values(array_filter(array_map(fn($f) => $f['name'], $old), fn($n) => isset($newByName[$n])));
    foreach ($new as $f) {
        $was = $oldByName[$f['name']] ?? null;
        if ($was === null) {
            $ops[] = ['op' => 'add_field', 'field' => $f] + $base;
            $current[] = $f['name'];
            continue;
        }
        $choices = ['list', 'boolean', 'select'];
        if (!editorSameKind($was, $f) && $was['type'] !== $f['type'] && (in_array($was['type'], $choices, true) || in_array($f['type'], $choices, true))) {
            // Da o verso un elenco, un interruttore o una scelta i valori non si convertono: il campo si ricrea
            $ops[] = ['op' => 'remove_field', 'name' => $f['name']] + $base;
            $ops[] = ['op' => 'add_field', 'field' => $f] + $base;
            $current = array_values(array_diff($current, [$f['name']]));
            $current[] = $f['name'];
            continue;
        }
        if (!editorSameKind($was, $f)) {
            $ops[] = ['op' => 'change_type', 'name' => $f['name'], 'type' => $f['type']] + ($f['type'] === 'list' ? [] : ['localized' => !empty($f['localized'])]) + $base;
        }
        if ($f['type'] === 'select' && (($was['options'] ?? []) !== $f['options'] || !empty($was['multiple']) !== $f['multiple'])) {
            $ops[] = ['op' => 'set_options', 'name' => $f['name'], 'options' => $f['options'], 'multiple' => $f['multiple']] + $base;
        }
        $describe = [];
        if (($was['description'] ?? '') !== ($f['description'] ?? '')) $describe['description'] = $f['description'] ?? '';
        if (!$path && ($was['section'] ?? '') !== ($f['section'] ?? '')) $describe['section'] = $f['section'] ?? '';
        if ($describe) $ops[] = ['op' => 'describe_field', 'name' => $f['name']] + $describe + $base;
        if ($f['type'] === 'list' && $was['type'] === 'list') {
            array_push($ops, ...editorSchemaOps($type, $was['of'] ?? [], $f['of'], [...$path, $f['name']]));
        }
    }
    // Ordine: un campo alla volta nella sua posizione
    foreach ($new as $i => $f) {
        $pos = array_search($f['name'], $current, true);
        if ($pos === $i) continue;
        array_splice($current, (int) $pos, 1);
        array_splice($current, $i, 0, [$f['name']]);
        $ops[] = ['op' => 'move_field', 'name' => $f['name'], 'to' => $i] + $base;
    }
    return $ops;
}

/**
 * Cosa perderebbero i contenuti con il nuovo schema: i campi tolti o
 * ricreati che hanno davvero dei valori. Un campo mai compilato non conta.
 */
function editorSchemaImpact(string $type, array $old, array $new, ?array $nodes = null, string $prefix = ''): array
{
    $nodes ??= editorItems($type);
    $newByName = array_column($new, null, 'name');
    $impact = [];
    foreach ($old as $f) {
        $now = $newByName[$f['name']] ?? null;
        $label = $prefix . $f['name'];
        $lost = $now === null ? 'viene rimosso' : null;
        $choices = ['list', 'boolean', 'select'];
        if ($now !== null && $now['type'] !== $f['type'] && (in_array($f['type'], $choices, true) || in_array($now['type'], $choices, true))) {
            $lost = 'cambia tipo: da «' . (EDITOR_FIELD_TYPES[$f['type']] ?? $f['type']) . '» a «' . (EDITOR_FIELD_TYPES[$now['type']] ?? $now['type']) . '»';
        }
        if ($now !== null && $now['type'] === 'select' && $f['type'] === 'select' && array_diff((array) ($f['options'] ?? []), $now['options'])) {
            $lost ??= 'perde le opzioni «' . implode('», «', array_diff((array) ($f['options'] ?? []), $now['options'])) . '»';
        }
        if ($lost !== null) {
            $samples = [];
            foreach ($nodes as $node) {
                $value = $node['values'][$f['name']] ?? null;
                if (editorIsEmpty($value)) continue;
                $samples[] = $f['type'] === 'list' ? count((array) $value) . ' elementi in «' . editorTitle($node) . '»' : (editorText($value) ?: editorTitle($node));
            }
            if ($samples) $impact[] = ['path' => $label, 'reason' => $lost, 'count' => count($samples), 'samples' => array_slice($samples, 0, 3)];
            continue;
        }
        if ($f['type'] === 'list' && $now['type'] === 'list') {
            $children = [];
            foreach ($nodes as $node) {
                foreach ((array) ($node['values'][$f['name']] ?? []) as $child) {
                    if (is_array($child)) $children[] = $child;
                }
            }
            array_push($impact, ...editorSchemaImpact($type, $f['of'] ?? [], $now['of'], $children, $label . ' / '));
        }
    }
    return $impact;
}

function editorIsEmpty(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $v) {
            if (!editorIsEmpty($v)) return false;
        }
        return true;
    }
    return $value === null || $value === '' || $value === false;
}
