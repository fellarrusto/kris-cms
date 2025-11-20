<?php
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
function getJson($path, $def = [])
{
    return file_exists($path) ? (json_decode(file_get_contents($path), true) ?? $def) : $def;
}
function saveJson($path, $data)
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        $newFields = [];
        if (isset($_POST['fields'])) {
            foreach ($_POST['fields'] as $f) {
                if (trim($f['name']) !== '') {
                    $newFields[] = ['name' => preg_replace('/[^a-z0-9_]/', '', strtolower($f['name'])), 'type' => $f['type']];
                }
            }
        }
        if (!empty($_POST['new_field_name'])) {
            $newFields[] = ['name' => preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['new_field_name'])), 'type' => $_POST['new_field_type']];
        }
        $models[$g] = $newFields;
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
    // 4. Salva Entità
    if (isset($_POST['save_entity'])) {
        $id = $_POST['id'];
        $g = $_POST['group'];
        $targetIdx = -1;
        foreach ($data as $i => $d) {
            if ($d['name'] === $g && $d['id'] == $id) {
                $targetIdx = $i;
                break;
            }
        }

        $itemData = [];
        if (isset($models[$g])) {
            foreach ($models[$g] as $def) {
                $fname = $def['name'];
                $ftype = $def['type'];
                if ($ftype === 'plain') {
                    $itemData[] = ['name' => $fname, 'type' => 'plain', 'value' => $_POST[$fname]['val'] ?? ''];
                } else {
                    $vals = [];
                    foreach ($activeLangs as $l)
                        $vals[$l] = $_POST[$fname][$l] ?? '';
                    $itemData[] = ['name' => $fname, 'type' => $ftype, 'value' => $vals];
                }
            }
        }
        $entityObj = ['id' => (int) $id, 'name' => $g, 'data' => $itemData];
        if ($targetIdx >= 0)
            $data[$targetIdx] = $entityObj;
        else
            $data[] = $entityObj;
        saveJson($dataFile, $data);
        $msg = "Contenuto salvato.";
    }
    // 5. Crea Istanza
    if (isset($_POST['create_instance'])) {
        $g = $_POST['group'];
        $maxId = -1;
        foreach ($data as $d)
            if ($d['name'] === $g)
                $maxId = max($maxId, $d['id']);
        $skeleton = [];
        foreach ($models[$g] as $f)
            $skeleton[] = ['name' => $f['name'], 'type' => $f['type'], 'value' => ($f['type'] == 'plain' ? '' : array_fill_keys($activeLangs, ''))];
        $newId = $maxId + 1;
        $data[] = ['id' => $newId, 'name' => $g, 'data' => $skeleton];
        saveJson($dataFile, $data);
        header("Location: index.php?action=edit&group=$g&id=$newId");
        exit;
    }
    // 6. Elimina Istanza
    if (isset($_POST['delete_instance'])) {
        $data = array_filter($data, fn($d) => !($d['name'] == $_POST['group'] && $d['id'] == $_POST['id']));
        saveJson($dataFile, array_values($data));
        header("Location: index.php?action=list&group=" . $_POST['group']);
        exit;
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
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --bg: #f3f4f6;
            --sidebar-bg: #111827;
            --sidebar-txt: #9ca3af;
            --surface: #ffffff;
            --border: #e5e7eb;
            --text: #1f2937;
            --danger: #ef4444;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* SIDEBAR */
        aside {
            width: 260px;
            background: var(--sidebar-bg);
            color: var(--sidebar-txt);
            display: flex;
            flex-direction: column;
            border-right: 1px solid #1f2937;
            flex-shrink: 0;
        }

        .brand {
            padding: 24px;
            font-size: 1.25rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.5px;
            border-bottom: 1px solid #1f2937;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand svg {
            color: var(--primary);
        }

        .nav-item {
            display: flex;
            align-items: center;
            padding: 12px 24px;
            color: inherit;
            text-decoration: none;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .nav-item svg {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            opacity: 0.7;
        }

        .nav-item:hover {
            background: #1f2937;
            color: #fff;
        }

        .nav-item:hover svg {
            opacity: 1;
        }

        .nav-item.active {
            background: var(--primary);
            color: #fff;
        }

        .nav-item.active svg {
            opacity: 1;
        }

        /* Logout Button */
        .logout-btn {
            margin-top: auto;
            border-top: 1px solid #1f2937;
            color: #ef4444;
        }
        .logout-btn:hover {
            background: #371b1b;
            color: #f87171;
        }

        /* MAIN CONTENT */
        main {
            flex: 1;
            overflow-y: auto;
            padding: 40px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding-bottom: 100px;
        }

        /* TYPOGRAPHY & UI */
        h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 25px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 .actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn svg {
            width: 18px;
            height: 18px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-white {
            background: white;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-white:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        /* FORMS */
        .card {
            background: var(--surface);
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .card-body {
            padding: 24px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
            font-size: 0.9rem;
        }

        input[type="text"],
        select,
        textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border 0.2s;
            background: #fff;
            box-sizing: border-box;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* DASHBOARD GRID */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
        }

        .dash-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 24px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
            height: 160px;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .dash-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.1);
            border-color: var(--primary);
        }

        .dash-card h3 {
            margin: 0;
            font-size: 1.25rem;
            color: #111827;
        }

        .dash-card p {
            margin: 5px 0 0;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .dash-card .count {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            opacity: 0.1;
            position: absolute;
            bottom: -10px;
            right: 10px;
        }

        .dash-add {
            border: 2px dashed #e5e7eb;
            background: transparent;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #6b7280;
        }

        .dash-add:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f9fafb;
            text-align: left;
            padding: 12px 20px;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            color: #374151;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #f9fafb;
        }

        /* TABS */
        .tabs-container {
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .tabs-header {
            display: flex;
            background: #f9fafb;
            border-bottom: 1px solid var(--border);
        }

        .tab-btn {
            padding: 12px 20px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            color: #6b7280;
            border-right: 1px solid var(--border);
            transition: background 0.1s;
        }

        .tab-btn:hover {
            background: #f3f4f6;
        }

        .tab-btn.active {
            background: white;
            color: var(--primary);
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            border-bottom-color: white;
        }

        .tab-content {
            display: none;
            padding: 20px;
            background: white;
        }

        .tab-content.active {
            display: block;
        }

        /* UTILS */
        .alert {
            padding: 16px;
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            border-radius: 6px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e5e7eb;
            color: #374151;
        }

        /* Modal */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999; /* Alto z-index per stare sopra a TinyMCE */
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(2px);
        }

        .modal {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
        }

        .modal-body {
            padding: 24px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 16px 24px;
            background: #f9fafb;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        /* TinyMCE Fixes */
        .tox-tinymce {
            border: 1px solid var(--border) !important;
            border-radius: 6px !important;
        }
    </style>
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
                            onclick="document.getElementById('createModal').style.display='none'">Annulla</button>
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
                        <form method="POST">
                            <input type="hidden" name="save_structure" value="1">
                            <input type="hidden" name="group_name" value="<?= $group ?>">

                            <div style="background:#f9fafb; padding:15px; border-radius:6px; margin-bottom:20px;">
                                <h4 style="margin:0 0 10px 0;">Campi Esistenti</h4>
                                <?php if (empty($models[$group]))
                                    echo "<p style='font-size:0.9rem; color:#6b7280;'>Nessun campo definito.</p>"; ?>

                                <?php foreach ($models[$group] as $idx => $f): ?>
                                    <div style="display:flex; gap:10px; margin-bottom:10px; align-items:center;">
                                        <input type="text" name="fields[<?= $idx ?>][name]" value="<?= $f['name'] ?>"
                                            placeholder="Nome campo (es. title)">
                                        <select name="fields[<?= $idx ?>][type]" style="width:250px">
                                            <option value="text" <?= $f['type'] == 'text' ? 'selected' : '' ?>>Testo Multilingua</option>
                                            <option value="richtext" <?= $f['type'] == 'richtext' ? 'selected' : '' ?>>Richtext (HTML / Editor)</option>
                                            <option value="image" <?= $f['type'] == 'image' ? 'selected' : '' ?>>Media / File</option>
                                            <option value="plain" <?= $f['type'] == 'plain' ? 'selected' : '' ?>>Testo Semplice (ID, Codici)</option>
                                        </select>
                                        <button type="button" class="btn btn-white" onclick="this.parentElement.remove()"
                                            style="color:var(--danger);">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="margin-bottom:20px; padding:15px; border:1px dashed #d1d5db; border-radius:6px;">
                                <h4 style="margin:0 0 10px 0;">Aggiungi Campo</h4>
                                <div style="display:flex; gap:10px;">
                                    <input type="text" name="new_field_name" placeholder="Nome nuovo campo...">
                                    <select name="new_field_type" style="width:250px">
                                        <option value="text">Testo Multilingua</option>
                                        <option value="richtext">Richtext (HTML / Editor)</option>
                                        <option value="image">Media / File</option>
                                        <option value="plain">Testo Semplice</option>
                                    </select>
                                </div>
                            </div>

                            <div
                                style="display:flex; justify-content:space-between; border-top:1px solid var(--border); padding-top:20px;">
                                <button type="submit" name="delete_collection" class="btn btn-white"
                                    style="color:var(--danger);"
                                    onclick="return confirm('ATTENZIONE: Stai per eliminare l\'intera collezione e la sua struttura. Continuare?')">Elimina
                                    Collezione</button>
                                <button type="submit" class="btn btn-primary">Salva Struttura</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php elseif ($action === 'edit'):
            $id = $_GET['id'];
            $entity = null;
            foreach ($data as $d)
                if ($d['name'] == $group && $d['id'] == $id)
                    $entity = $d;
            $schema = $models[$group];
            $getVal = fn($n) => array_column($entity['data'], 'value', 'name')[$n] ?? null;
            ?>
            <div class="container">
                <h1>Modifica Elemento <span class="badge" style="font-size:1rem; margin-left:10px;">#<?= $id ?></span>
                    <a href="?action=list&group=<?= $group ?>" class="btn btn-white">Annulla</a>
                </h1>

                <form method="POST" class="card">
                    <div class="card-body">
                        <input type="hidden" name="save_entity" value="1">
                        <input type="hidden" name="group" value="<?= $group ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">

                        <?php foreach ($schema as $f):
                            $n = $f['name'];
                            $t = $f['type'];
                            $saved = $getVal($n); ?>
                            <div style="margin-bottom:30px;">
                                <label><?= $n ?> <span
                                        style="font-weight:normal; color:#9ca3af; font-size:0.8em; margin-left:5px;"><?= strtoupper($t) ?></span></label>

                                <?php if ($t === 'plain'): ?>
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
            </div>

        <?php elseif ($action === 'media'): ?>
            <div class="container">
                <h1>Media Library</h1>
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-body" style="background:#f9fafb;">
                        <form method="POST" enctype="multipart/form-data"
                            style="display:flex; gap:10px; align-items:center;">
                            <input type="file" name="file" style="background:white;">
                            <button class="btn btn-primary">Carica File</button>
                        </form>
                    </div>
                </div>

                <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
                    <?php foreach ($images as $img):
                        $url = $uploadUrl . basename($img); ?>
                        <div class="card" onclick="prompt('URL del file:', '<?= $url ?>')"
                            style="cursor:pointer; transition:transform 0.1s;">
                            <div
                                style="aspect-ratio:1; overflow:hidden; border-bottom:1px solid var(--border); background:#eee; display:flex; align-items:center; justify-content:center;">
                                <img src="<?= $url ?>" style="width:100%; height:100%; object-fit:cover;">
                            </div>
                            <div
                                style="padding:10px; font-size:0.8rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= basename($img) ?>
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
                <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap:15px;">
                    <?php foreach ($images as $img):
                        $url = $uploadUrl . basename($img); ?>
                        <div onclick="selectMedia('<?= $url ?>')"
                            style="background:white; border-radius:6px; overflow:hidden; cursor:pointer; border:2px solid transparent; box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                            <img src="<?= $url ?>" style="width:100%; aspect-ratio:1; object-fit:cover;">
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
        let tinymceCallback = null; // Callback per l'editor Rich Text

        // Apertura per Input Normali
        function pickMedia(id) { 
            tgt = id; 
            tinymceCallback = null; // Resetta modo TinyMCE
            document.getElementById('mediaOverlay').style.display = 'flex'; 
        }

        // Apertura per TinyMCE
        function openCmsMediaPicker(callback, value, meta) {
            tinymceCallback = callback;
            tgt = null; // Resetta modo Input
            document.getElementById('mediaOverlay').style.display = 'flex';
        }

        // Selezione file universale
        function selectMedia(u) { 
            if(tinymceCallback) {
                // Siamo in modalità TinyMCE -> Inseriamo l'URL nel dialog di TinyMCE
                tinymceCallback(u, { title: u.split('/').pop() });
                tinymceCallback = null;
            } else if(tgt) {
                // Siamo in modalità Input classico
                document.getElementById(tgt).value = u; 
            }
            document.getElementById('mediaOverlay').style.display = 'none'; 
        }

        function openTab(el, cid, grp) {
            document.querySelectorAll('.group-' + grp).forEach(x => x.classList.remove('active'));
            document.getElementById(cid).classList.add('active');
            el.parentElement.querySelectorAll('.tab-btn').forEach(x => x.classList.remove('active'));
            el.classList.add('active');
        }

        // Inizializza TinyMCE
        document.addEventListener("DOMContentLoaded", function() {
            if(document.querySelector('.richtext')) {
                tinymce.init({
                    selector: '.richtext',
                    height: 400,
                    menubar: false,
                    plugins: 'image link lists code',
                    toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist | link image | code',
                    // Integrazione File Manager Custom
                    file_picker_callback: openCmsMediaPicker,
                    content_style: 'body { font-family:Segoe UI,Arial,sans-serif; font-size:14px }'
                });
            }
        });
    </script>
</body>
</html>