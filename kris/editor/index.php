<?php
declare(strict_types=1);

/**
 * KRIS 2 CMS - Professional UI Edition (Secured)
 * File: kris/editor/index.php (servito dallo stub editor/index.php)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/auth.php';

// I file interni del pannello controllano questa costante: aperti
// direttamente via URL si fermano invece di eseguire qualcosa.
define('KRIS_EDITOR', true);

require_once __DIR__ . '/helpers.php';

use Kris\Entity\StorageException;
use Kris\Update\Migrator;
use Kris\Update\SiteState;
use Kris\Update\UpdateException;

// Cookie di sessione non leggibile da JavaScript e non inviato cross-site.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// Use the script path even when the editor is opened without a trailing slash.
$BASE = $_SERVER['SCRIPT_NAME'];
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== $BASE) {
    header('Location: ' . $BASE . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Used by an open editor after a re-login: refresh the token without
// reloading (and losing) its unsaved fields.
if (isset($_GET['editor_session']) && ($_SERVER['HTTP_X_KRIS_EDITOR'] ?? '') === 'session') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(kris_is_logged_in() ? 200 : 403);
    echo json_encode(kris_is_logged_in() ? ['csrf' => kris_csrf_token()] : ['error' => 'Sessione scaduta.']);
    exit;
}

// Il token CSRF viene inserito automaticamente in ogni form POST della pagina.
ob_start('kris_inject_csrf');

// --- SISTEMA DI LOGIN (GATEKEEPER) ---

// Logout
if (isset($_GET['logout'])) {
    kris_logout();
    header("Location: $BASE");
    exit;
}

$login_error = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Primo avvio: nessuna credenziale configurata, la si crea adesso.
if (kris_auth_config() === null) {
    if ($isPost && isset($_POST['do_setup'])) {
        $user = trim((string) ($_POST['user'] ?? ''));
        $pass = (string) ($_POST['pass'] ?? '');
        if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
            kris_render_setup('Sessione scaduta: riprova.');
        } elseif ($user === '' || strlen($pass) < 10) {
            kris_render_setup('Serve uno username e una password di almeno 10 caratteri.');
        } elseif ($pass !== (string) ($_POST['pass2'] ?? '')) {
            kris_render_setup('Le due password non coincidono.');
        } elseif (!kris_save_credentials($user, $pass)) {
            kris_render_setup('Non riesco a scrivere config/auth.php: controlla i permessi della cartella.');
        } else {
            header("Location: $BASE");
            exit;
        }
    }
    kris_render_setup();
}

// Login
if ($isPost && isset($_POST['do_login'])) {
    if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
        $login_error = "Sessione scaduta: riprova.";
    } elseif (kris_login((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''))) {
        header("Location: $BASE");
        exit;
    } else {
        $login_error = "Credenziali non valide.";
    }
}

// Gatekeeper: la pagina di login vive in auth.php
if (!kris_is_logged_in()) {
    kris_render_login($login_error);
}

// --- FINE LOGIN --- (Il CMS inizia qui sotto)

// --- CONFIGURAZIONE ---
$dataFile = KRIS_ROOT . '/data/k_data.json';
$modelFile = KRIS_ROOT . '/data/k_model.json';
$settingsFile = KRIS_ROOT . '/data/cms_settings.json';
$uploadDir = KRIS_ROOT . '/assets/uploads/';
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

// --- STATO DEL FRAMEWORK ---
// Prima di leggere i contenuti: una migrazione puo cambiarli. Se
// $editorLock non e vuoto l'editor resta consultabile ma non salva nulla.
$krisVersion = SiteState::frameworkVersion();
$editorLock = '';
$frameworkNotice = '';
$migrationsPending = [];
$maintenance = kris_maintenance();
if ($maintenance !== null) {
    $editorLock = $maintenance['stale']
        ? 'Un aggiornamento di Kris si è interrotto. Apri Impostazioni › Versione di Kris per rimettere in ordine il sito.'
        : 'È in corso un aggiornamento di Kris: le modifiche sono sospese per qualche minuto.';
} else {
    try {
        $siteState = SiteState::load();
        $migrator = new Migrator(KRIS_ROOT, KRIS_DIR . '/migrations');
        if ($migrator->dataIsNewer($siteState)) {
            $editorLock = "I contenuti sono stati salvati da una versione di Kris più recente di questa ({$krisVersion}). "
                . 'Per non rovinarli l\'editor è in sola lettura: rimetti sul server la cartella kris/ della versione corretta.';
        } else {
            $pending = $migrator->pending($siteState);
            $runNow = $pending && ($migrator->pendingAreAutomatic($siteState)
                || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migrations'])
                    && kris_csrf_valid($_POST['csrf'] ?? null)));
            if ($runNow) {
                $siteState = $migrator->run($siteState);
                $pending = [];
            }
            $recorded = $siteState['framework_version'] ?? null;
            if ($pending) {
                $migrationsPending = $pending;
                $editorLock = 'Questa versione di Kris deve aggiornare il formato dei contenuti prima che tu possa modificarli.';
            } elseif (is_string($recorded) && version_compare($krisVersion, $recorded, '<')) {
                $frameworkNotice = "È installata Kris {$krisVersion}, più vecchia della {$recorded} registrata su questo sito: "
                    . 'forse la cartella kris/ è stata sovrascritta con una copia datata.';
            } elseif ($recorded !== $krisVersion || $runNow) {
                // Prima registrazione, o file caricati a mano via FTP.
                if (is_string($recorded) && $recorded !== $krisVersion) {
                    $siteState = SiteState::addHistory($siteState, [
                        'from' => $recorded, 'to' => $krisVersion, 'user' => (string) ($_SESSION['kris_user'] ?? ''),
                        'outcome' => 'ftp', 'message' => 'Aggiornato caricando i file a mano.',
                    ]);
                }
                $siteState['framework_version'] = $krisVersion;
                SiteState::save($siteState);
            }
            if ($runNow && isset($_POST['run_migrations'])) {
                header("Location: $BASE");
                exit;
            }
        }
    } catch (StorageException | UpdateException $e) {
        $frameworkNotice = 'Non riesco a leggere lo stato degli aggiornamenti di Kris. I contenuti non sono coinvolti: segnalalo a chi gestisce il sito.';
    }
}

// --- DATI ---
try {
    $data = getJson($dataFile, []);
    $models = getJson($modelFile, []);
    $settings = getJson($settingsFile, ['languages' => ['it', 'en']]);
} catch (StorageException $e) {
    renderStorageError($e->getMessage());
}
$activeLangs = $settings['languages'];

$action = $_GET['action'] ?? 'dashboard';
$group = $_GET['group'] ?? null;
$msg = '';
$error = '';

// Path assoluto a questo file (es. "/editor/index.php"), per redirect e form action
// indipendenti dall'URL corrente (con o senza trailing slash, con o senza index.php).
$BASE = $_SERVER['SCRIPT_NAME'];



// --- AZIONI POST ---
require __DIR__ . '/actions.php';
// View Data
$counts = [];
foreach ($models as $k => $v)
    $counts[$k] = 0;
foreach ($data as $d) {
    if (isset($counts[$d['name']]))
        $counts[$d['name']]++;
}
$images = editorMediaFiles($uploadDir);
$postSources = editorPostSources($models, $data);
// Un elemento di un elenco posts si apre dalla sezione Posts e vi ritorna.
$editPath = parsePath(is_string($_GET['path'] ?? null) ? $_GET['path'] : '');
$inPosts = $action === 'posts' || ($action === 'edit' && is_string($group) && count($editPath) === 2
    && postsFieldDef($models, $group, $editPath[0]) !== null);
if (isset($_GET['upload_error']) && is_string($_GET['upload_error'])) $error = $_GET['upload_error'];
if (in_array($action, ['edit', 'list', 'structure'], true) && (!is_string($group) || !isset($models[$group]))) {
    $action = 'dashboard';
    $error = 'La raccolta non è disponibile. Scegli uno dei contenuti qui sotto.';
}
$sectionLabel = match ($action) {
    'media' => 'Libreria media', 'settings' => 'Impostazioni', 'posts' => 'Posts',
    'structure', 'structure_impact' => 'Struttura', default => 'Contenuti',
};

// I file statici dell'editor stanno in kris/editor/, mentre la pagina e
// servita dallo stub in editor/: si risale alla root del sito dall'URL.
$assetBase = htmlspecialchars(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/kris/editor');
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="kris-csrf" content="<?= htmlspecialchars(kris_csrf_token()) ?>">
    <title><?= h($sectionLabel) ?> · Kris CMS</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
    <link rel="stylesheet" href="<?= $assetBase ?>/css/style.css">
</head>

<body data-editor-action="<?= h($action) ?>">
    <a class="skip-link" href="#main">Vai al contenuto</a>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>

    <div class="editor-workspace">
    <header class="topbar">
        <nav class="breadcrumbs" aria-label="Percorso"><a href="?action=dashboard">Il tuo sito</a><span aria-hidden="true">/</span><span><?= h($sectionLabel) ?></span><?php if ($group): ?><span aria-hidden="true">/</span><strong><?= h(editorLabel($group)) ?></strong><?php endif; ?></nav>
        <a href="../" target="_blank" rel="noopener" class="btn btn-white">Apri il sito <span aria-hidden="true">↗</span></a>
    </header>
    <main id="main" tabindex="-1">
        <div id="request-feedback" class="alert alert-error" role="alert" hidden></div>
        <?php if ($editorLock !== ''): ?>
            <div class="alert alert-error system-alert" role="alert">
                <p><strong>Modifiche sospese.</strong> <?= h($editorLock) ?></p>
                <?php if ($migrationsPending): ?>
                    <form method="POST" class="system-alert-action"><button class="btn btn-primary" name="run_migrations" value="1">Aggiorna il formato dei contenuti</button><small>Prima viene salvata una copia dei file dei contenuti.</small></form>
                <?php elseif (!empty($maintenance['stale']) && $action !== 'settings'): ?>
                    <a class="btn btn-white" href="?action=settings#versione">Vai alla versione di Kris</a>
                <?php endif; ?>
            </div>
        <?php elseif ($frameworkNotice !== ''): ?>
            <div class="alert system-alert" role="status"><p><?= h($frameworkNotice) ?></p></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($msg): ?>
            <div class="alert" role="status">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>


        <?php
        // Router: la vista si sceglie da una mappa esplicita, mai
        // costruendo un percorso con quello che arriva dall'URL.
        $views = [
            'dashboard'        => 'dashboard.php',
            'list'             => 'list.php',
            'posts'            => 'posts.php',
            'structure'        => 'structure.php',
            'structure_impact' => 'structure_impact.php',
            'edit'             => 'edit.php',
            'media'            => 'media.php',
            'settings'         => 'settings.php',
        ];
        require __DIR__ . '/views/' . ($views[$action] ?? $views['dashboard']);
        ?>
    </main>
    </div>

    <?php require __DIR__ . '/partials/media_overlay.php'; ?>
    <dialog id="confirmDialog" class="confirm-dialog" aria-labelledby="confirmTitle">
        <div class="modal-header"><h2 id="confirmTitle"></h2><button type="button" class="icon-button" data-close-dialog aria-label="Chiudi"><?= uiIcon('close') ?></button></div>
        <div class="modal-body"><p id="confirmText"></p></div><div class="modal-footer" id="confirmActions"></div>
    </dialog>
    <div id="toast" class="toast" role="status" hidden></div>

    <script src="<?= $assetBase ?>/js/scripts.js"></script>
    <?php if ($action === 'structure'): ?>
        <script src="<?= $assetBase ?>/js/structure.js"></script>
    <?php endif; ?>
    <?php if ($action === 'settings'): ?>
        <script src="<?= $assetBase ?>/js/update.js"></script>
    <?php endif; ?>
</body>

</html>
