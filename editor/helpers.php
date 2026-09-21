<?php
declare(strict_types=1);

use Kris\Entity\JsonStore;
if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * Funzioni pure del pannello: lettura/scrittura JSON, navigazione dei
 * percorsi annidati, schema e rendering dei campi della schermata Struttura.
 */

// Lettura e scrittura passano da JsonStore: un archivio illeggibile solleva
// un'eccezione invece di diventare un array vuoto (che alla prima azione
// successiva verrebbe riscritto, cancellando tutti i contenuti).
function getJson(string $path, array $def = []): array
{
    return (new JsonStore($path))->read($def);
}
function saveJson(string $path, array $data): void
{
    (new JsonStore($path))->write($data);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function editorLabel(string $name): string
{
    return ucfirst(str_replace('_', ' ', $name));
}

function editorTitle(array $entity): string
{
    foreach (['title', 'page_title', 'name', 'meta_title', null] as $preferred) {
        foreach ($entity['data'] ?? [] as $field) {
            if ($preferred !== null && $field['name'] !== $preferred) continue;
            if (!in_array($field['type'] ?? '', ['text', 'plain', 'richtext'], true)) continue;
            $value = $field['value'] ?? '';
            foreach (is_array($value) ? $value : [$value] as $text) {
                if (is_scalar($text) && trim(strip_tags((string) $text)) !== '') {
                    return mb_substr(trim(strip_tags((string) $text)), 0, 85);
                }
            }
        }
    }
    return 'Contenuto #' . ($entity['id'] ?? '');
}

function editorMediaFiles(string $directory): array
{
    $extensions = [...\Kris\Entity\MediaStore::allowedExtensions(), 'svg'];
    $files = array_values(array_filter(glob($directory . '*') ?: [],
        fn($file) => is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)));
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files;
}

function editorMediaSource(string $url): string
{
    if ($url === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return preg_match('~^https?://~i', $url) ? $url : '';
    }
    return str_starts_with($url, '/') || str_starts_with($url, '../') ? $url : '../' . $url;
}

function uiIcon(string $name): string
{
    $paths = [
        'content' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'media' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 6"/>',
        'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="16" cy="17" r="3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'back' => '<path d="M19 12H5m6-6-6 6 6 6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"/>',
        'up' => '<path d="m6 14 6-6 6 6"/>',
        'down' => '<path d="m6 10 6 6 6-6"/>',
        'trash' => '<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>',
        'structure' => '<rect x="8" y="3" width="8" height="5" rx="1"/><rect x="3" y="16" width="6" height="5" rx="1"/><rect x="15" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M6 16v-4h12v4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['content']) . '</svg>';
}

/** Pagina di errore bloccante: mostrata quando i dati non sono leggibili. */
function renderStorageError(string $detail): never
{
    http_response_code(500);
    $detail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="it"><head><meta charset="UTF-8">
    <title>Archivio non disponibile - Kris CMS</title>
    <style>
        body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; background:#f3f4f6;
               display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .box { background:#fff; padding:36px; border-radius:12px; max-width:560px;
               box-shadow:0 4px 6px rgba(0,0,0,.1); border-top:4px solid #ef4444; }
        h1 { margin:0 0 12px; font-size:1.3rem; color:#111827; }
        p { color:#4b5563; line-height:1.55; }
        code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:.85rem;
               display:block; margin-top:14px; color:#b91c1c; word-break:break-all; }
    </style></head><body><div class="box">
        <h1>I contenuti non sono disponibili</h1>
        <p><strong>Non e stato modificato nulla.</strong> L'archivio dei contenuti non e
        leggibile, quindi il pannello si e fermato per non sovrascriverlo.</p>
        <p>Le copie di sicurezza si trovano in <code>data/backups/</code>: ripristina la piu
        recente rinominandola al posto del file danneggiato, poi ricarica questa pagina.</p>
        <code>{$detail}</code>
    </div></body></html>
    HTML;
    exit;
}

// --- NESTED PATH HELPERS ---
// Path format: "fieldName/subId/fieldName/subId..." (may end on a field name for list views)
function parsePath(?string $raw): array
{
    if ($raw === null || $raw === '') return [];
    return array_values(array_filter(explode('/', $raw), fn($s) => $s !== ''));
}

function pathToString(array $path): string
{
    return implode('/', $path);
}

// Walk the path inside a root entity ($rootEntity has keys id/name/data).
// Returns a reference to the node addressed by the path:
//   - even-length path  → sub-entity ['id'=>..., 'data'=>[...]]
//   - odd-length path   → field definition ['name', 'type'=>'array', 'value'=>[...]]
//   - empty path        → the root entity itself
function &walkEntityPath(array &$rootEntity, array $path)
{
    $cur = &$rootEntity;
    $i = 0;
    $n = count($path);
    while ($i < $n) {
        $fieldName = $path[$i];
        $fieldIdx = -1;
        foreach ($cur['data'] as $k => $d) {
            if ($d['name'] === $fieldName) { $fieldIdx = $k; break; }
        }
        if ($fieldIdx < 0 || ($cur['data'][$fieldIdx]['type'] ?? null) !== 'array') {
            $null = null; return $null;
        }
        $field = &$cur['data'][$fieldIdx];
        if ($i + 1 >= $n) {
            return $field;
        }
        $subId = (int) $path[$i + 1];
        $subIdx = -1;
        foreach ($field['value'] as $k => $s) {
            if ((int) ($s['id'] ?? -1) === $subId) { $subIdx = $k; break; }
        }
        if ($subIdx < 0) { $null = null; return $null; }
        unset($cur);
        $cur = &$field['value'][$subIdx];
        unset($field);
        $i += 2;
    }
    return $cur;
}

// Walk the model path: returns the schema (list of field defs) that applies at the position.
// - Empty path         → schema of the root group
// - Ends on field name → the sub-schema 'of' of that array field (used for children)
// - Ends on sub-id     → the sub-schema 'of' (used for an individual sub-entity form)
function resolveSchemaAtPath(array $models, string $rootGroup, array $path): array
{
    $schema = $models[$rootGroup] ?? [];
    $i = 0;
    $n = count($path);
    while ($i < $n) {
        $fieldName = $path[$i];
        $found = null;
        foreach ($schema as $f) {
            if ($f['name'] === $fieldName) { $found = $f; break; }
        }
        if ($found === null || ($found['type'] ?? null) !== 'array') return [];
        $schema = $found['of'] ?? [];
        $i += ($i + 1 < $n) ? 2 : 1;
    }
    return $schema;
}

function findRootIndex(array $data, string $group, int $id): int
{
    foreach ($data as $i => $d) {
        if ($d['name'] === $group && (int) $d['id'] === $id) return $i;
    }
    return -1;
}

// Build an empty skeleton for a schema. Array fields default to empty list.
function buildSkeleton(array $schema, array $activeLangs): array
{
    $out = [];
    foreach ($schema as $f) {
        if (($f['type'] ?? null) === 'array') {
            $out[] = ['name' => $f['name'], 'type' => 'array', 'value' => []];
        } elseif ($f['type'] === 'plain') {
            $out[] = ['name' => $f['name'], 'type' => 'plain', 'value' => ''];
        } else {
            $out[] = ['name' => $f['name'], 'type' => $f['type'], 'value' => array_fill_keys($activeLangs, '')];
        }
    }
    return $out;
}

// Nomi che il form usa per i propri controlli: un campo dello schema che si
// chiamasse cosi sovrascriverebbe $_POST['id'] o $_POST['group'] e manderebbe
// il salvataggio in errore (o creerebbe entita duplicate). I valori dei campi
// viaggiano quindi dentro f[...], fuori dalla portata di questi nomi.
const RESERVED_FIELD_NAMES = ['id', 'group', 'path', 'save_entity', 'create_instance',
    'create_nested', 'delete_nested', 'delete_instance', 'reorder_root', 'reorder_nested',
    'save_structure', 'delete_collection', 'save_settings', 'delete_media', 'file_name',
    'order', 'group_name', 'schema_json', 'collection_name', 'langs', 'f'];

/**
 * Controlla uno schema prima di salvarlo: tipi ammessi, nomi validi, nomi
 * duplicati e nomi riservati. Restituisce la lista dei problemi trovati.
 */
function validateSchema(array $schema, array $previous, string $where = ''): array
{
    $allowedTypes = ['text', 'richtext', 'image', 'plain', 'array'];
    $problems = [];
    $seen = [];

    foreach ($schema as $f) {
        $name = $f['name'] ?? '';
        $type = $f['type'] ?? '';
        $label = $where === '' ? "«{$name}»" : "«{$where} / {$name}»";

        if (!is_string($name) || !preg_match('/^[a-z0-9_]+$/', $name)) {
            $problems[] = "il nome {$label} non e valido (ammessi lettere minuscole, numeri e _).";
            continue;
        }
        if (in_array($name, RESERVED_FIELD_NAMES, true)) {
            $problems[] = "«{$name}» e un nome riservato dal pannello: usane un altro (es. «{$name}_campo»).";
        }
        if (isset($seen[$name])) {
            $problems[] = "il campo {$label} e presente due volte.";
        }
        $seen[$name] = true;

        if (!in_array($type, $allowedTypes, true)) {
            $problems[] = "il tipo del campo {$label} non e riconosciuto.";
        }
        if ($type === 'array') {
            $problems = array_merge(
                $problems,
                validateSchema($f['of'] ?? [], [], $where === '' ? $name : "{$where} / {$name}")
            );
        }
    }

    return $problems;
}

/**
 * Il builder dell'interfaccia invia solo nome, tipo e sotto-campi: le
 * descrizioni (e ogni altro metadato aggiunto a mano nel modello) vanno
 * riportate, altrimenti si perderebbero al primo salvataggio dalla UI.
 */
function mergeSchemaMetadata(array $new, array $previous): array
{
    $byName = [];
    foreach ($previous as $f) {
        if (isset($f['name'])) $byName[$f['name']] = $f;
    }

    $out = [];
    foreach ($new as $f) {
        $old = $byName[$f['name']] ?? [];
        foreach ($old as $key => $value) {
            // 'of' viene ricalcolato ricorsivamente, il resto si conserva
            if (!array_key_exists($key, $f) && $key !== 'of') {
                $f[$key] = $value;
            }
        }
        if (($f['type'] ?? '') === 'array') {
            $f['of'] = mergeSchemaMetadata($f['of'] ?? [], $old['of'] ?? []);
        }
        $out[] = $f;
    }
    return $out;
}

/**
 * Mappa "percorso del campo => tipo" di uno schema, scendendo negli array.
 * Es: ['title' => 'text', 'features' => 'array', 'features/title' => 'text'].
 */
function schemaFieldMap(array $schema, string $prefix = ''): array
{
    $map = [];
    foreach ($schema as $f) {
        $name = $f['name'] ?? null;
        if (!is_string($name) || $name === '') continue;

        $path = $prefix === '' ? $name : $prefix . '/' . $name;
        $map[$path] = (string) ($f['type'] ?? '');

        if (($f['type'] ?? '') === 'array') {
            $map += schemaFieldMap($f['of'] ?? [], $path);
        }
    }
    return $map;
}

/** Valori non vuoti di un campo, in forma leggibile. */
function fieldValuePreviews(array $field): array
{
    $value = $field['value'] ?? null;

    // Per una lista si elenca un elemento per riga, identificato dal suo primo
    // testo compilato: "3 elementi" dice molto meno di "Strategia e identita".
    if (($field['type'] ?? '') === 'array') {
        $out = [];
        foreach (is_array($value) ? $value : [] as $child) {
            $label = '';
            foreach (is_array($child['data'] ?? null) ? $child['data'] : [] as $sub) {
                $preview = fieldValuePreviews($sub);
                if ($preview !== []) { $label = $preview[0]; break; }
            }
            $out[] = $label !== '' ? $label : ('elemento #' . ($child['id'] ?? '?'));
        }
        return $out;
    }

    $out = [];
    foreach (is_array($value) ? $value : [$value] as $lang => $single) {
        if (is_string($single) && trim($single) !== '') {
            $text = trim(strip_tags($single));
            $out[] = (is_string($lang) ? strtoupper($lang) . ': ' : '') . mb_substr($text, 0, 60);
        }
    }
    return $out;
}

/** Cerca nei dati tutti i valori presenti a un certo percorso di schema. */
function collectValuesAtPath(array $dataLists, array $segments): array
{
    $segment = array_shift($segments);
    $values = [];

    foreach ($dataLists as $data) {
        foreach ($data as $field) {
            if (($field['name'] ?? null) !== $segment) continue;

            if ($segments === []) {
                $values = array_merge($values, fieldValuePreviews($field));
            } elseif (($field['type'] ?? '') === 'array' && is_array($field['value'] ?? null)) {
                $children = [];
                foreach ($field['value'] as $child) {
                    if (is_array($child['data'] ?? null)) $children[] = $child['data'];
                }
                $values = array_merge($values, collectValuesAtPath($children, $segments));
            }
        }
    }
    return $values;
}

/**
 * Cosa perderebbero i contenuti applicando il nuovo schema.
 *
 * Elenca solo i campi che spariscono o cambiano tipo E che hanno davvero
 * qualcosa dentro: togliere un campo mai compilato non e un problema e non
 * deve far comparire un avviso che poi si impara a ignorare.
 */
function schemaImpact(array $oldSchema, array $newSchema, array $data, string $group): array
{
    $old = schemaFieldMap($oldSchema);
    $new = schemaFieldMap($newSchema);

    $dataLists = [];
    foreach ($data as $entity) {
        if (($entity['name'] ?? null) === $group && is_array($entity['data'] ?? null)) {
            $dataLists[] = $entity['data'];
        }
    }

    $impact = [];
    foreach ($old as $path => $type) {
        if (!isset($new[$path])) {
            $reason = 'viene rimosso';
        } elseif ($new[$path] !== $type) {
            $reason = sprintf('cambia tipo da «%s» a «%s»', $type, $new[$path]);
        } else {
            continue;
        }

        $values = collectValuesAtPath($dataLists, explode('/', $path));
        if ($values === []) continue;

        $impact[] = [
            'path'    => $path,
            'type'    => $type,
            'reason'  => $reason,
            'count'   => count($values),
            'samples' => array_slice($values, 0, 3),
        ];
    }

    return $impact;
}

/** Valori dei campi inviati dal form di modifica (sempre sotto la chiave "f"). */
function postedFields(array $post): array
{
    return is_array($post['f'] ?? null) ? $post['f'] : [];
}

// Apply POSTed fields onto an existing data[] list, preserving array-type values (which are managed separately).
function applyPostToData(array $existing, array $schema, array $post, array $activeLangs): array
{
    // Index existing by field name for lookup (to preserve array values)
    $byName = [];
    foreach ($existing as $item) $byName[$item['name']] = $item;

    $fields = postedFields($post);

    $out = [];
    foreach ($schema as $def) {
        $fname = $def['name'];
        $ftype = $def['type'];
        if ($ftype === 'array') {
            // preserve existing nested value, or start empty
            $out[] = ['name' => $fname, 'type' => 'array', 'value' => $byName[$fname]['value'] ?? []];
        } elseif ($ftype === 'plain') {
            $out[] = ['name' => $fname, 'type' => 'plain', 'value' => $fields[$fname]['val'] ?? ''];
        } else {
            // Si parte dalle traduzioni gia salvate: una lingua disattivata
            // nelle impostazioni non compare nel form, e non deve sparire
            // dal file solo perche non e stata reinviata.
            $vals = is_array($byName[$fname]['value'] ?? null) ? $byName[$fname]['value'] : [];
            foreach ($activeLangs as $l) $vals[$l] = $fields[$fname][$l] ?? '';
            $out[] = ['name' => $fname, 'type' => $ftype, 'value' => $vals];
        }
    }
    return $out;
}

// Render ricorsivo dei campi dello schema nell'editor struttura.
// $depth controlla l'indentazione visiva (max consigliato: 3-4 livelli).
function renderSchemaFields(array $schema, int $depth = 0): void
{
    $typeLabels = [
        'text'     => 'Testo Multilingua',
        'richtext' => 'Richtext',
        'image'    => 'Media / File',
        'plain'    => 'Testo Semplice',
        'array'    => 'Array (lista innestata)',
    ];
    foreach ($schema as $f):
        $isArray = ($f['type'] ?? '') === 'array'; ?>
        <div class="sf-row" data-depth="<?= $depth ?>">
            <div class="sf-header">
                <input type="text" class="sf-name" value="<?= htmlspecialchars($f['name'] ?? '') ?>" placeholder="Nome campo (es. title)" aria-label="Nome tecnico del campo" required pattern="[a-z0-9_]+">
                <select class="sf-type" aria-label="Tipo del campo">
                    <?php foreach ($typeLabels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($f['type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="icon-button danger sf-remove" aria-label="Rimuovi campo"><?= uiIcon('trash') ?></button>
            </div>
            <label class="sf-description-label">Descrizione visibile<input type="text" class="sf-description" value="<?= h($f['description'] ?? '') ?>" placeholder="Aiuta chi modifica il contenuto"></label>
            <div class="sf-nested" <?= $isArray ? '' : 'style="display:none"' ?>>
                <?php if ($isArray): renderSchemaFields($f['of'] ?? [], $depth + 1); endif; ?>
                <button type="button" class="btn btn-white sf-add-child">+ Sotto-campo</button>
            </div>
        </div>
    <?php endforeach;
}

