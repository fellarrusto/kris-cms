<?php
declare(strict_types=1);

/**
 * KRIS 2 CMS - Professional UI Edition (Secured)
 * File: /editor/index.php
 */

session_start();

// --- SISTEMA DI LOGIN (GATEKEEPER) ---
$ADMIN_USER = 'admin';      // <--- Cambia il tuo username
$ADMIN_PASS = 'password';   // <--- Cambia la tua password

// Logout Logic
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Login Logic
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    if ($_POST['user'] === $ADMIN_USER && $_POST['pass'] === $ADMIN_PASS) {
        $_SESSION['kris_auth'] = true;
        header("Location: index.php");
        exit;
    } else {
        $login_error = "Credenziali non valide.";
    }
}

// Gatekeeper Check
if (!isset($_SESSION['kris_auth']) || $_SESSION['kris_auth'] !== true) {
?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Kris CMS</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .login-card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 350px; text-align: center; }
            h2 { margin: 0 0 20px 0; color: #111827; font-size: 1.5rem; }
            input { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #e5e7eb; border-radius: 6px; box-sizing: border-box; font-size: 1rem; }
            button { width: 100%; padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
            button:hover { background: #2563eb; }
            .error { background: #fee2e2; color: #b91c1c; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9rem; }
            .brand { font-weight: 800; color: #3b82f6; margin-bottom: 10px; display: inline-block; letter-spacing: -1px; font-size: 1.2rem;}
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="brand">KRIS CMS</div>
            <h2>Accesso Riservato</h2>
            <?php if($login_error): ?><div class="error"><?= $login_error ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="do_login" value="1">
                <input type="text" name="user" placeholder="Username" required autofocus>
                <input type="password" name="pass" placeholder="Password" required>
                <button type="submit">Accedi</button>
            </form>
        </div>
    </body>
    </html>
<?php
    exit; // <--- BLOCCO CRITICO: Ferma l'esecuzione se non loggato
}

// --- FINE LOGIN --- (Il CMS inizia qui sotto)

// --- CONFIGURAZIONE ---
$dataFile = __DIR__ . '/../data/k_data.json';
$modelFile = __DIR__ . '/../data/k_model.json';
$settingsFile = __DIR__ . '/../data/cms_settings.json';
$uploadDir = __DIR__ . '/../assets/uploads/';
$uploadUrl = 'assets/uploads/';

$DEFAULT_LANGS = [
    'it' => 'Italiano',
    'en' => 'English',
    'es' => 'Español',
    'fr' => 'Français',
    'de' => 'Deutsch'
];

// Init Files/Dir
if (!is_dir($uploadDir))
    mkdir($uploadDir, 0777, true);
if (!file_exists($modelFile))
    file_put_contents($modelFile, '{}');
if (!file_exists($dataFile))
    file_put_contents($dataFile, '[]');

// --- HELPER ---
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

// Apply POSTed fields onto an existing data[] list, preserving array-type values (which are managed separately).
function applyPostToData(array $existing, array $schema, array $post, array $activeLangs): array
{
    // Index existing by field name for lookup (to preserve array values)
    $byName = [];
    foreach ($existing as $item) $byName[$item['name']] = $item;

    $out = [];
    foreach ($schema as $def) {
        $fname = $def['name'];
        $ftype = $def['type'];
        if ($ftype === 'array') {
            // preserve existing nested value, or start empty
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

// --- DATI ---
$data = getJson($dataFile);
$models = getJson($modelFile);
$settings = getJson($settingsFile, ['languages' => ['it', 'en']]);
$activeLangs = $settings['languages'];

$action = $_GET['action'] ?? 'dashboard';
$group = $_GET['group'] ?? null;
$msg = '';

// --- LOGICA POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Crea Collezione
    if (isset($_POST['create_collection'])) {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['collection_name']));
        if ($name && !isset($models[$name])) {
            $models[$name] = [];
            saveJson($modelFile, $models);
            header("Location: index.php?action=structure&group=$name");
            exit;
        }
    }
    // 2. Salva Struttura
    if (isset($_POST['save_structure'])) {
        $g = $_POST['group_name'];
        $decoded = json_decode($_POST['schema_json'] ?? '[]', true);
        $models[$g] = is_array($decoded) ? $decoded : [];
        saveJson($modelFile, $models);
        $msg = "Struttura aggiornata con successo.";
    }
    // 3. Elimina Collezione
    if (isset($_POST['delete_collection'])) {
        unset($models[$_POST['group_name']]);
        saveJson($modelFile, $models);
        header("Location: index.php");
        exit;
    }
    // 4. Salva Entità (root o nested via path)
    if (isset($_POST['save_entity'])) {
        $id = (int) $_POST['id'];
        $g = $_POST['group'];
        $path = parsePath($_POST['path'] ?? '');
        $rootIdx = findRootIndex($data, $g, $id);

        $schema = resolveSchemaAtPath($models, $g, $path);

        if ($rootIdx < 0) {
            // Only root can be created via this path (nested entities are created via create_nested)
            $itemData = applyPostToData([], $schema, $_POST, $activeLangs);
            $data[] = ['id' => $id, 'name' => $g, 'data' => $itemData];
        } else {
            $target = &walkEntityPath($data[$rootIdx], $path);
            if ($target === null) {
                $msg = "Percorso non valido.";
            } else {
                $target['data'] = applyPostToData($target['data'] ?? [], $schema, $_POST, $activeLangs);
            }
            unset($target);
        }
        saveJson($dataFile, $data);
        $msg = "Contenuto salvato.";
    }
    // 5. Crea Istanza (root)
    if (isset($_POST['create_instance'])) {
        $g = $_POST['group'];
        $maxId = -1;
        foreach ($data as $d)
            if ($d['name'] === $g)
                $maxId = max($maxId, $d['id']);
        $skeleton = buildSkeleton($models[$g] ?? [], $activeLangs);
        $newId = $maxId + 1;
        $data[] = ['id' => $newId, 'name' => $g, 'data' => $skeleton];
        saveJson($dataFile, $data);
        header("Location: index.php?action=edit&group=$g&id=$newId");
        exit;
    }
    // 5b. Crea sub-istanza nested (path deve terminare su un field array)
    if (isset($_POST['create_nested'])) {
        $g = $_POST['group'];
        $id = (int) $_POST['id'];
        $path = parsePath($_POST['path'] ?? '');
        $rootIdx = findRootIndex($data, $g, $id);
        if ($rootIdx >= 0 && count($path) % 2 === 1) {
            $schema = resolveSchemaAtPath($models, $g, $path);
            $field = &walkEntityPath($data[$rootIdx], $path);
            if ($field !== null && ($field['type'] ?? null) === 'array') {
                $maxSubId = -1;
                foreach ($field['value'] as $s)
                    $maxSubId = max($maxSubId, (int) ($s['id'] ?? -1));
                $newSubId = $maxSubId + 1;
                $field['value'][] = ['id' => $newSubId, 'data' => buildSkeleton($schema, $activeLangs)];
                saveJson($dataFile, $data);
                $childPath = pathToString([...$path, (string) $newSubId]);
                header("Location: index.php?action=edit&group=$g&id=$id&path=" . urlencode($childPath));
                exit;
            }
            unset($field);
        }
    }
    // 6. Elimina Istanza (root)
    if (isset($_POST['delete_instance'])) {
        $data = array_filter($data, fn($d) => !($d['name'] == $_POST['group'] && $d['id'] == $_POST['id']));
        saveJson($dataFile, array_values($data));
        header("Location: index.php?action=list&group=" . $_POST['group']);
        exit;
    }
    // 6b. Elimina sub-istanza nested (path deve terminare su un sub-id)
    if (isset($_POST['delete_nested'])) {
        $g = $_POST['group'];
        $id = (int) $_POST['id'];
        $path = parsePath($_POST['path'] ?? '');
        $rootIdx = findRootIndex($data, $g, $id);
        if ($rootIdx >= 0 && count($path) >= 2 && count($path) % 2 === 0) {
            $parentPath = array_slice($path, 0, -2);
            $fieldName = $path[count($path) - 2];
            $subId = (int) $path[count($path) - 1];
            $parent = &walkEntityPath($data[$rootIdx], $parentPath);
            if ($parent !== null) {
                foreach ($parent['data'] as &$item) {
                    if ($item['name'] === $fieldName && ($item['type'] ?? null) === 'array') {
                        $item['value'] = array_values(array_filter(
                            $item['value'],
                            fn($s) => (int) ($s['id'] ?? -1) !== $subId
                        ));
                        break;
                    }
                }
                unset($item);
            }
            unset($parent);
            saveJson($dataFile, $data);
            $back = pathToString($parentPath);
            header("Location: index.php?action=edit&group=$g&id=$id" . ($back ? '&path=' . urlencode($back) : ''));
            exit;
        }
    }
    // 7. Upload & Settings
    if (isset($_FILES['file'])) {
        move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . preg_replace('/[^a-z0-9-_\.]/i', '', basename($_FILES['file']['name'])));
    }
    if (isset($_POST['save_settings'])) {
        saveJson($settingsFile, ['languages' => $_POST['langs'] ?? ['it']]);
        header("Location: index.php?action=settings");
        exit;
    }
    // 8. Elimina Media
    if (isset($_POST['delete_media'])) {
        // basename() è fondamentale per la sicurezza (impedisce di cancellare file di sistema con ../)
        $filename = basename($_POST['file_name']); 
        $targetFile = $uploadDir . $filename;
        
        if (file_exists($targetFile)) {
            unlink($targetFile); // Cancella fisicamente il file
            $msg = "File eliminato con successo.";
        } else {
            $msg = "Errore: File non trovato.";
        }
    }
}

// View Data
$counts = [];
foreach ($models as $k => $v)
    $counts[$k] = 0;
foreach ($data as $d) {
    if (isset($counts[$d['name']]))
        $counts[$d['name']]++;
}
$images = glob($uploadDir . '*.{jpg,png,svg,webp,jpeg,gif}', GLOB_BRACE);
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kris 2 CMS</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/css/style.css') ?>">
</head>

<body>

    <aside>
        <div class="brand">
            KRIS CMS
        </div>

        <a href="?action=dashboard" class="nav-item <?= $action == 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
            </svg>
            Dashboard
        </a>
        <a href="?action=media" class="nav-item <?= $action == 'media' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                <polyline points="21 15 16 10 5 21"></polyline>
            </svg>
            Media Library
        </a>
        <a href="?action=settings" class="nav-item <?= $action == 'settings' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"></circle>
                <path
                    d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z">
                </path>
            </svg>
            Impostazioni
        </a>

        <a href="?logout=1" class="nav-item logout-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Esci
        </a>

    </aside>

    <main>
        <?php if ($msg): ?>
            <div class="alert">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <?php if ($action === 'dashboard'): ?>
            <div class="container">
                <h1>Dashboard</h1>
                <div class="grid">
                    <?php foreach ($models as $name => $fields): ?>
                        <a href="?action=list&group=<?= $name ?>" class="dash-card">
                            <div>
                                <h3><?= ucfirst($name) ?></h3>
                                <p><?= count($fields) ?> campi configurati</p>
                            </div>
                            <div class="count"><?= $counts[$name] ?></div>
                        </a>
                    <?php endforeach; ?>

                    <button class="dash-card dash-add"
                        onclick="document.getElementById('createModal').style.display='flex'">
                        <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        <span style="margin-top:10px; font-weight:500;">Nuova Raccolta</span>
                    </button>
                </div>
            </div>

            <div id="createModal" class="modal-backdrop">
                <form method="POST" class="modal">
                    <div class="modal-header">
                        <h3>Crea Nuova Collezione</h3>
                        <button type="button" class="btn-white"
                            onclick="document.getElementById('createModal').style.display='none'"
                            style="border:none; padding:5px;">✕</button>
                    </div>
                    <div class="modal-body">
                        <label>Nome della collezione (es. articoli, team, servizi)</label>
                        <input type="text" name="collection_name" required placeholder="nome_collezione" autofocus>
                        <p style="font-size:0.85rem; color:#6b7280; margin-top:10px;">Verrà creato un nuovo modello vuoto
                            che potrai configurare.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-white"
                            onclick="document.getElementById('createModal').style.display='none'">Back</button>
                        <button name="create_collection" class="btn btn-primary">Crea Collezione</button>
                    </div>
                </form>
            </div>

        <?php elseif ($action === 'list'): ?>
            <div class="container">
                <h1>
                    <?= ucfirst($group) ?>
                    <div class="actions">
                        <a href="?action=structure&group=<?= $group ?>" class="btn btn-white">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                            Struttura
                        </a>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="group" value="<?= $group ?>">
                            <button name="create_instance" class="btn btn-primary">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                                Nuovo Elemento
                            </button>
                        </form>
                    </div>
                </h1>

                <div class="card">
                    <?php if (empty($models[$group])): ?>
                        <div style="padding:40px; text-align:center; color:#6b7280;">
                            <p style="margin-bottom:15px;">Non hai ancora definito i campi per questa collezione.</p>
                            <a href="?action=structure&group=<?= $group ?>" class="btn btn-primary">Definisci Struttura</a>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th width="60">ID</th>
                                    <th>Anteprima Contenuto</th>
                                    <th width="140" style="text-align:right">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $list = array_filter($data, fn($i) => $i['name'] === $group);
                                if (empty($list)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; padding:30px; color:#9ca3af;">Nessun elemento
                                            presente.</td>
                                    </tr>
                                <?php else:
                                    foreach ($list as $item):
                                        $prev = '<em style="color:#9ca3af">Vuoto</em>';
                                        foreach ($item['data'] as $d) {
                                            if (in_array($d['type'], ['text', 'plain', 'richtext'])) {
                                                $v = is_array($d['value']) ? reset($d['value']) : $d['value'];
                                                if ($v) {
                                                    $prev = mb_substr(strip_tags($v), 0, 70) . '...';
                                                    break;
                                                }
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td><span class="badge">#<?= $item['id'] ?></span></td>
                                            <td><?= $prev ?></td>
                                            <td style="text-align:right;">
                                                <a href="?action=edit&group=<?= $group ?>&id=<?= $item['id'] ?>" class="btn btn-white"
                                                    style="padding:6px 10px;">Edit</a>
                                                <form method="POST" style="display:inline"
                                                    onsubmit="return confirm('Eliminare definitivamente questo elemento?');">
                                                    <input type="hidden" name="group" value="<?= $group ?>"><input type="hidden"
                                                        name="id" value="<?= $item['id'] ?>">
                                                    <button name="delete_instance" class="btn btn-white"
                                                        style="padding:6px 10px; color:var(--danger); border-color:var(--border);">✕</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($action === 'structure'): ?>
            <div class="container">
                <h1>
                    <span>Struttura: <?= ucfirst($group) ?></span>
                    <a href="?action=list&group=<?= $group ?>" class="btn btn-white">← Torna ai Dati</a>
                </h1>

                <div class="card">
                    <div class="card-body">
                        <form method="POST" id="structureForm">
                            <input type="hidden" name="save_structure" value="1">
                            <input type="hidden" name="group_name" value="<?= $group ?>">
                            <input type="hidden" name="schema_json" id="schema_json">

                            <div id="root-schema" class="sf-container">
                                <?php renderSchemaFields($models[$group] ?? []); ?>
                            </div>
                            <button type="button" class="btn btn-white" style="margin-top:8px;"
                                onclick="sfAddField(document.getElementById('root-schema'))">+ Campo</button>

                            <div style="display:flex; justify-content:space-between; border-top:1px solid var(--border); padding-top:20px; margin-top:20px;">
                                <button type="submit" name="delete_collection" class="btn btn-white"
                                    style="color:var(--danger);"
                                    onclick="return confirm('ATTENZIONE: Stai per eliminare l\'intera collezione e la sua struttura. Continuare?')">Elimina Collezione</button>
                                <button type="submit" class="btn btn-primary">Salva Struttura</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <style>
                .sf-container { display:flex; flex-direction:column; gap:6px; }
                .sf-row { background:#f9fafb; border:1px solid var(--border); border-radius:6px; padding:10px; }
                .sf-header { display:flex; gap:8px; align-items:center; }
                .sf-header .sf-name { flex:1; min-width:0; }
                .sf-header .sf-type { width:200px; flex-shrink:0; }
                .sf-nested {
                    margin-top:10px;
                    padding:10px;
                    background:#fff;
                    border-left:3px solid var(--primary, #3b82f6);
                    border-radius:0 4px 4px 0;
                    display:flex;
                    flex-direction:column;
                    gap:6px;
                }
                .sf-add-child { align-self:flex-start; font-size:0.85rem; padding:4px 10px; }
            </style>

            <script>
                const SF_TYPES = [
                    ['text',     'Testo Multilingua'],
                    ['richtext', 'Richtext'],
                    ['image',    'Media / File'],
                    ['plain',    'Testo Semplice'],
                    ['array',    'Array (lista innestata)'],
                ];

                function sfTypeChange(select) {
                    const nested = select.closest('.sf-row').querySelector('.sf-nested');
                    nested.style.display = select.value === 'array' ? 'flex' : 'none';
                }

                function sfAddField(container) {
                    const typeOptions = SF_TYPES.map(([v, l]) =>
                        `<option value="${v}">${l}</option>`).join('');
                    const row = document.createElement('div');
                    row.className = 'sf-row';
                    row.innerHTML = `
                        <div class="sf-header">
                            <input type="text" class="sf-name" placeholder="Nome campo (es. title)">
                            <select class="sf-type" onchange="sfTypeChange(this)">${typeOptions}</select>
                            <button type="button" class="btn btn-white sf-remove" style="color:var(--danger);">✕</button>
                        </div>
                        <div class="sf-nested" style="display:none">
                            <button type="button" class="btn btn-white sf-add-child"
                                onclick="sfAddField(this.closest('.sf-nested'))">+ Sotto-campo</button>
                        </div>`;
                    row.querySelector('.sf-remove').addEventListener('click', () => row.remove());
                    const addBtn = [...container.children].find(c => c.tagName === 'BUTTON' && c.classList.contains('sf-add-child'));
                    container.insertBefore(row, addBtn ?? null);
                }

                function sfSerialize(container) {
                    return [...container.children]
                        .filter(c => c.classList.contains('sf-row'))
                        .map(row => {
                            const name = row.querySelector('.sf-name').value
                                .trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
                            const type = row.querySelector('.sf-type').value;
                            if (!name) return null;
                            const entry = { name, type };
                            if (type === 'array') {
                                entry.of = sfSerialize(row.querySelector('.sf-nested'));
                            }
                            return entry;
                        })
                        .filter(Boolean);
                }

                // Wire remove buttons on server-rendered rows
                document.querySelectorAll('.sf-remove').forEach(btn => {
                    btn.addEventListener('click', () => btn.closest('.sf-row').remove());
                });

                document.getElementById('structureForm').addEventListener('submit', function() {
                    document.getElementById('schema_json').value =
                        JSON.stringify(sfSerialize(document.getElementById('root-schema')));
                });
            </script>

        <?php elseif ($action === 'edit'):
            $id = (int) $_GET['id'];
            $pathRaw = $_GET['path'] ?? '';
            $path = parsePath($pathRaw);
            $rootIdx = findRootIndex($data, $group, $id);
            $rootEntity = $rootIdx >= 0 ? $data[$rootIdx] : null;

            // Navigate to the target sub-entity
            $entity = null;
            if ($rootEntity !== null) {
                if (empty($path)) {
                    $entity = $rootEntity;
                } else {
                    $rootCopy = $rootEntity;
                    $target = &walkEntityPath($rootCopy, $path);
                    if ($target !== null && isset($target['data'])) {
                        $entity = ['id' => $target['id'] ?? 0, 'data' => $target['data']];
                    }
                    unset($target);
                }
            }
            $schema = resolveSchemaAtPath($models, $group, $path);
            $getVal = fn($n) => $entity ? (array_column($entity['data'], 'value', 'name')[$n] ?? null) : null;

            // Breadcrumb
            $crumbs = [['label' => ucfirst($group), 'href' => "?action=list&group={$group}"]];
            if (!empty($path)) {
                $crumbs[] = ['label' => "#{$id}", 'href' => "?action=edit&group={$group}&id={$id}"];
                for ($i = 0; $i < count($path); $i += 2) {
                    $prefix = pathToString(array_slice($path, 0, $i + 2));
                    $crumbs[] = [
                        'label' => $path[$i] . ' #' . ($path[$i + 1] ?? '?'),
                        'href' => "?action=edit&group={$group}&id={$id}&path=" . urlencode($prefix)
                    ];
                }
            }
            $backHref = $crumbs[count($crumbs) - 2]['href'] ?? "?action=list&group={$group}";
            $crumbLabel = end($crumbs)['label'];
            ?>
            <div class="container">
                <h1>Modifica <?= htmlspecialchars($crumbLabel) ?>
                    <?php if (!empty($path)): ?>
                        <span class="badge" style="font-size:0.85rem; margin-left:10px;">#<?= (int) ($entity['id'] ?? 0) ?></span>
                    <?php else: ?>
                        <span class="badge" style="font-size:1rem; margin-left:10px;">#<?= $id ?></span>
                    <?php endif; ?>
                    <a href="<?= $backHref ?>" class="btn btn-white" onclick="return confirmExit(event)">Back</a>
                </h1>

                <?php if (count($crumbs) > 1): ?>
                    <div style="margin-bottom:20px; font-size:0.9rem; color:#6b7280;">
                        <?php foreach ($crumbs as $i => $c): ?>
                            <?php if ($i > 0) echo '<span style="margin:0 6px;">/</span>'; ?>
                            <a href="<?= $c['href'] ?>" style="color:#374151; text-decoration:none;"><?= htmlspecialchars($c['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($entity === null): ?>
                    <div class="card"><div class="card-body">Elemento non trovato.</div></div>
                <?php else: ?>
                <form method="POST" class="card">
                    <div class="card-body">
                        <input type="hidden" name="save_entity" value="1">
                        <input type="hidden" name="group" value="<?= $group ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="path" value="<?= htmlspecialchars($pathRaw) ?>">

                        <?php foreach ($schema as $f):
                            $n = $f['name'];
                            $t = $f['type'];
                            $saved = $getVal($n); ?>
                            <div style="margin-bottom:30px;">
                                <?php if (!empty($f['description'])): ?>
                                    <label style="font-size:1rem; font-weight:600; color:#111827;"><?= htmlspecialchars($f['description']) ?></label>
                                    <div style="margin:2px 0 8px; font-size:0.78rem; color:#9ca3af; font-family:monospace;"><?= $n ?> <span style="margin-left:6px;"><?= strtoupper($t) ?></span></div>
                                <?php else: ?>
                                    <label><?= $n ?> <span style="font-weight:normal; color:#9ca3af; font-size:0.8em; margin-left:5px;"><?= strtoupper($t) ?></span></label>
                                <?php endif; ?>

                                <?php if ($t === 'array'):
                                    $children = is_array($saved) ? $saved : [];
                                    $childPathPrefix = $pathRaw === '' ? $n : ($pathRaw . '/' . $n);
                                    ?>
                                    <div style="background:#f9fafb; border:1px solid var(--border); border-radius:6px; padding:15px;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                            <span style="color:#6b7280; font-size:0.9rem;">
                                                <?= count($children) ?> elemento/i
                                            </span>
                                            <button type="button" class="btn btn-white"
                                                onclick="document.getElementById('create_nested_<?= $n ?>').submit()">+ Nuovo</button>
                                        </div>
                                        <?php if (empty($children)): ?>
                                            <p style="color:#9ca3af; margin:0; font-size:0.9rem;">Nessun elemento. Aggiungi il primo.</p>
                                        <?php else: ?>
                                            <table>
                                                <thead>
                                                    <tr><th width="60">ID</th><th>Anteprima</th><th width="140" style="text-align:right">Azioni</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($children as $ch):
                                                        $chId = (int) ($ch['id'] ?? 0);
                                                        $prev = '<em style="color:#9ca3af">Vuoto</em>';
                                                        foreach ($ch['data'] ?? [] as $d) {
                                                            if (in_array($d['type'] ?? '', ['text','plain','richtext'])) {
                                                                $v = is_array($d['value']) ? reset($d['value']) : $d['value'];
                                                                if ($v) { $prev = mb_substr(strip_tags((string)$v), 0, 70) . '...'; break; }
                                                            }
                                                        }
                                                        $childPath = $childPathPrefix . '/' . $chId;
                                                        ?>
                                                        <tr>
                                                            <td><span class="badge">#<?= $chId ?></span></td>
                                                            <td><?= $prev ?></td>
                                                            <td style="text-align:right;">
                                                                <a href="?action=edit&group=<?= $group ?>&id=<?= $id ?>&path=<?= urlencode($childPath) ?>"
                                                                    class="btn btn-white" style="padding:6px 10px;">Edit</a>
                                                                <button type="button" class="btn btn-white"
                                                                    style="padding:6px 10px; color:var(--danger); border-color:var(--border);"
                                                                    onclick="if(confirm('Eliminare questo elemento?')){document.getElementById('del_nested_<?= $n ?>_<?= $chId ?>').submit();}">✕</button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($t === 'plain'): ?>
                                    <textarea name="<?= $n ?>[val]" rows="2"><?= is_array($saved) ? '' : $saved ?></textarea>

                                <?php else: ?>
                                    <div class="tabs-container">
                                        <div class="tabs-header">
                                            <?php foreach ($activeLangs as $i => $l): ?>
                                                <div class="tab-btn <?= $i == 0 ? 'active' : '' ?>"
                                                    onclick="openTab(this, '<?= $n ?>_<?= $l ?>', '<?= $n ?>')">
                                                    <?= strtoupper($l) ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php foreach ($activeLangs as $i => $l):
                                            $v = is_array($saved) ? ($saved[$l] ?? '') : ''; ?>
                                            <div class="tab-content group-<?= $n ?> <?= $i == 0 ? 'active' : '' ?>" id="<?= $n ?>_<?= $l ?>">
                                                <?php if ($t === 'image'): ?>
                                                    <div style="display:flex; gap:10px; align-items:center;">
                                                        <input type="text" name="<?= $n ?>[<?= $l ?>]" value="<?= $v ?>"
                                                            id="in_<?= $n ?>_<?= $l ?>" placeholder="../assets/uploads/...">
                                                        <button type="button" class="btn btn-white"
                                                            onclick="pickMedia('in_<?= $n ?>_<?= $l ?>')">Scegli</button>
                                                    </div>
                                                    <?php if ($v):
                                                        $previewSrc = (strpos($v, '../') === false && strpos($v, 'http') !== 0) ? '../' . $v : $v;
                                                        ?>
                                                        <div
                                                            style="margin-top:10px; padding:5px; border:1px solid var(--border); border-radius:6px; display:inline-block; background:white;">
                                                            <img src="<?= $previewSrc ?>"
                                                                style="height:100px; display:block; object-fit:cover;">
                                                        </div>
                                                    <?php endif; ?>
                                                
                                                <?php elseif ($t === 'richtext'): ?>
                                                    <textarea name="<?= $n ?>[<?= $l ?>]" class="richtext"><?= htmlspecialchars($v) ?></textarea>

                                                <?php else: ?>
                                                    <textarea name="<?= $n ?>[<?= $l ?>]" rows="4"
                                                        style="min-height:100px;"><?= $v ?></textarea>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="modal-footer" style="background:#f9fafb; border-top:1px solid var(--border);">
                        <button class="btn btn-primary" style="padding:12px 30px; font-size:1rem;">Salva Modifiche</button>
                    </div>
                </form>

                <?php // Helper forms for nested create/delete (outside the main edit form)
                foreach ($schema as $f):
                    if (($f['type'] ?? '') !== 'array') continue;
                    $n = $f['name'];
                    $saved = $getVal($n);
                    $children = is_array($saved) ? $saved : [];
                    $childPathPrefix = $pathRaw === '' ? $n : ($pathRaw . '/' . $n);
                    ?>
                    <form method="POST" id="create_nested_<?= $n ?>" style="display:none;">
                        <input type="hidden" name="create_nested" value="1">
                        <input type="hidden" name="group" value="<?= $group ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="path" value="<?= htmlspecialchars($childPathPrefix) ?>">
                    </form>
                    <?php foreach ($children as $ch):
                        $chId = (int) ($ch['id'] ?? 0);
                        $childPath = $childPathPrefix . '/' . $chId;
                        ?>
                        <form method="POST" id="del_nested_<?= $n ?>_<?= $chId ?>" style="display:none;">
                            <input type="hidden" name="delete_nested" value="1">
                            <input type="hidden" name="group" value="<?= $group ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="path" value="<?= htmlspecialchars($childPath) ?>">
                        </form>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($action === 'media'): ?>
            <div class="container">
                <h1>Media Library</h1>
                
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-body" style="background:#f9fafb;">
                        <form method="POST" enctype="multipart/form-data" action="./upload.php"
                            style="display:flex; gap:10px; align-items:center;">
                            <input type="file" name="file" style="background:white;" required>
                            <button class="btn btn-primary">Carica File</button>
                        </form>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
                    <?php foreach ($images as $img):
                        $fileName = basename($img);
                        $publicUrl = $uploadUrl . $fileName; 
                    ?>
                        <div class="card" style="transition:transform 0.1s; position: relative;">
                            
                            <form method="POST" onsubmit="return confirm('Sei sicuro di voler eliminare definitivamente <?= $fileName ?>?')" 
                                  style="position: absolute; top: 5px; right: 5px; z-index: 10;">
                                <input type="hidden" name="delete_media" value="1">
                                <input type="hidden" name="file_name" value="<?= $fileName ?>">
                                <button type="submit" 
                                        onclick="event.stopPropagation();" 
                                        style="background:#ef4444; color:white; border:none; border-radius:50%; width:24px; height:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                    ✕
                                </button>
                            </form>

                            <div onclick="prompt('URL da copiare:', '<?= $publicUrl ?>')" style="cursor:pointer;">
                                <div style="aspect-ratio:1; overflow:hidden; border-bottom:1px solid var(--border); background:#eee; display:flex; align-items:center; justify-content:center;">
                                    <img src="../<?= $publicUrl ?>" style="width:100%; height:100%; object-fit:cover;">
                                </div>
                                <div style="padding:10px; font-size:0.8rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#374151;">
                                    <?= $fileName ?>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($action === 'settings'): ?>
            <div class="container">
                <h1>Impostazioni</h1>
                <form method="POST" class="card">
                    <div class="card-body">
                        <h3>Lingue supportate</h3>
                        <p style="color:#6b7280; margin-bottom:20px;">Seleziona le lingue che vuoi gestire nel CMS.</p>
                        <div class="grid">
                            <?php foreach ($DEFAULT_LANGS as $code => $label): ?>
                                <label class="card"
                                    style="padding:15px; display:flex; align-items:center; gap:10px; cursor:pointer;">
                                    <input type="checkbox" name="langs[]" value="<?= $code ?>"
                                        <?= in_array($code, $activeLangs) ? 'checked' : '' ?> style="width:auto;">
                                    <div>
                                        <strong><?= $label ?></strong>
                                        <div style="font-size:0.8em; color:#9ca3af;"><?= strtoupper($code) ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button name="save_settings" class="btn btn-primary">Salva Impostazioni</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <div id="mediaOverlay" class="modal-backdrop">
        <div class="modal" style="max-width:800px; height:80vh; display:flex; flex-direction:column;">
            <div class="modal-header">
                <h3>Seleziona File</h3>
                <button onclick="document.getElementById('mediaOverlay').style.display='none'" class="btn-white"
                    style="border:none;">✕</button>
            </div>
            <div class="modal-body" style="background:#f3f4f6;">
                <div id="mediaGrid" class="grid" style="grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap:15px;">
                    <div onclick="document.getElementById('overlayUpload').click()"
                        style="background:white; border-radius:6px; cursor:pointer; border:2px dashed #d1d5db; box-shadow:0 1px 2px rgba(0,0,0,0.1); display:flex; flex-direction:column; align-items:center; justify-content:center; aspect-ratio:1; color:#6b7280;"
                        onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'"
                        onmouseout="this.style.borderColor='#d1d5db';this.style.color='#6b7280'">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span style="font-size:0.7rem; margin-top:5px;">Carica</span>
                    </div>
                    <input type="file" id="overlayUpload" style="display:none" accept="image/*" onchange="uploadFromOverlay(this)">
                    <?php foreach ($images as $img):
                        $url = $uploadUrl . basename($img); ?>
                        <div onclick="selectMedia('<?= $url ?>')"
                            style="background:white; border-radius:6px; overflow:hidden; cursor:pointer; border:2px solid transparent; box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                            <img src="../<?= $url ?>" style="width:100%; aspect-ratio:1; object-fit:cover;">
                            <div
                                style="padding:5px; font-size:0.7rem; text-align:center; overflow:hidden; white-space:nowrap;">
                                <?= basename($img) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        let tgt = null;
        let tinymceCallback = null;
        // Variabile globale per tracciare le modifiche
        let hasUnsavedChanges = false;

        // --- GESTIONE MODIFICHE ---
        function markAsDirty() {
            hasUnsavedChanges = true;
        }

        function confirmExit(e) {
            if (hasUnsavedChanges) {
                const choice = confirm("Hai modifiche non salvate.\nSe esci ora, andranno perse.\n\nSei sicuro di voler uscire?");
                if (!choice) {
                    e.preventDefault(); // Blocca il click
                    return false;
                }
            }
            return true; // Procede
        }

        // --- GESTIONE MEDIA ---
        function pickMedia(id) { 
            tgt = id; 
            tinymceCallback = null; 
            document.getElementById('mediaOverlay').style.display = 'flex'; 
        }

        function openCmsMediaPicker(callback, value, meta) {
            tinymceCallback = callback;
            tgt = null; 
            document.getElementById('mediaOverlay').style.display = 'flex'; 
        }

        function selectMedia(u) { 
            if(tinymceCallback) {
                tinymceCallback(u, { title: u.split('/').pop() });
                tinymceCallback = null;
                markAsDirty();
            } else if(tgt) {
                document.getElementById(tgt).value = u;
                // Aggiorna preview
                var container = document.getElementById(tgt).closest('.tab-content');
                var preview = container.querySelector('img');
                if (preview) {
                    preview.src = '../' + u;
                } else {
                    var div = document.createElement('div');
                    div.style.cssText = 'margin-top:10px;padding:5px;border:1px solid var(--border);border-radius:6px;display:inline-block;background:white';
                    div.innerHTML = '<img src="../' + u + '" style="height:100px;display:block;object-fit:cover">';
                    container.appendChild(div);
                }
                markAsDirty();
            }
            document.getElementById('mediaOverlay').style.display = 'none'; 
        }

        function openTab(el, cid, grp) {
            document.querySelectorAll('.group-' + grp).forEach(x => x.classList.remove('active'));
            document.getElementById(cid).classList.add('active');
            el.parentElement.querySelectorAll('.tab-btn').forEach(x => x.classList.remove('active'));
            el.classList.add('active');
        }

        function uploadFromOverlay(input) {
            if (!input.files[0]) return;
            const form = new FormData();
            form.append('file', input.files[0]);

            fetch('./upload.php', {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    const card = document.createElement('div');
                    card.onclick = () => selectMedia(data.url);
                    card.style.cssText = 'background:white;border-radius:6px;overflow:hidden;cursor:pointer;border:2px solid transparent;box-shadow:0 1px 2px rgba(0,0,0,0.1)';
                    card.innerHTML = '<img src="../' + data.url + '" style="width:100%;aspect-ratio:1;object-fit:cover"><div style="padding:5px;font-size:0.7rem;text-align:center;overflow:hidden;white-space:nowrap">' + data.url.split('/').pop() + '</div>';
                    document.getElementById('mediaGrid').children[1].after(card);
                    input.value = '';
                });
        }

        // --- INIZIALIZZAZIONE ---
        document.addEventListener("DOMContentLoaded", function() {

            // 1. Rileva modifiche su input normali (text, textarea)
            document.querySelectorAll('form.card input, form.card textarea, form.card select').forEach(el => {
                el.addEventListener('input', markAsDirty);
                el.addEventListener('change', markAsDirty);
            });

            // 2. Reset dirty flag al submit del form (salvataggio reale)
            const form = document.querySelector('form.card');
            if (form) form.addEventListener('submit', () => { hasUnsavedChanges = false; });

            // 3. Dialog nativo solo su refresh/chiusura tab — non blocca ogni click nella pagina
            window.addEventListener('beforeunload', function(e) {
                if (hasUnsavedChanges) e.preventDefault();
            });

            // 4. Inizializza TinyMCE
            if (document.querySelector('.richtext')) {
                tinymce.init({
                    selector: '.richtext',
                    height: 400,
                    menubar: false,
                    plugins: 'image link lists code fullscreen',
                    toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist | link image | code fullscreen',
                    file_picker_callback: openCmsMediaPicker,
                    content_style: 'body { font-family:Segoe UI,Arial,sans-serif; font-size:14px }',
                    setup: function(editor) {
                        // isReady evita che gli eventi di init di TinyMCE marchino dirty
                        let isReady = false;
                        editor.on('init', function() {
                            setTimeout(() => { isReady = true; }, 300);
                        });
                        editor.on('change', function() { if (isReady) markAsDirty(); });
                        editor.on('keyup',  function() { if (isReady) markAsDirty(); });
                    }
                });
            }
        });
    </script>
</body>
</html>