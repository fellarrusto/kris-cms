<?php
declare(strict_types=1);

// --- JSON I/O ---
function getJson(string $path, array $def = []): array
{
    return file_exists($path) ? (json_decode(file_get_contents($path), true) ?? $def) : $def;
}

function saveJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

// Apply POSTed fields onto an existing data[] list, preserving array-type values.
function applyPostToData(array $existing, array $schema, array $post, array $activeLangs): array
{
    $byName = [];
    foreach ($existing as $item) $byName[$item['name']] = $item;

    $out = [];
    foreach ($schema as $def) {
        $fname = $def['name'];
        $ftype = $def['type'];
        if ($ftype === 'array') {
            $out[] = ['name' => $fname, 'type' => 'array', 'value' => $byName[$fname]['value'] ?? []];
        } elseif ($ftype === 'plain') {
            $out[] = ['name' => $fname, 'type' => 'plain', 'value' => $post[$fname]['val'] ?? ''];
        } else {
            $vals = [];
            foreach ($activeLangs as $l) $vals[$l] = $post[$fname][$l] ?? '';
            $out[] = ['name' => $fname, 'type' => $ftype, 'value' => $vals];
        }
    }
    return $out;
}

// Render ricorsivo dei campi dello schema nell'editor struttura.
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
                <input type="text" class="sf-name" value="<?= htmlspecialchars($f['name'] ?? '') ?>" placeholder="Nome campo (es. title)">
                <select class="sf-type" onchange="sfTypeChange(this)">
                    <?php foreach ($typeLabels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($f['type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-white sf-remove" style="color:var(--danger);">✕</button>
            </div>
            <div class="sf-nested" <?= $isArray ? '' : 'style="display:none"' ?>>
                <?php if ($isArray): renderSchemaFields($f['of'] ?? [], $depth + 1); endif; ?>
                <button type="button" class="btn btn-white sf-add-child" onclick="sfAddField(this.closest('.sf-nested'))">+ Sotto-campo</button>
            </div>
        </div>
    <?php endforeach;
}
