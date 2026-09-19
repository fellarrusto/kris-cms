<?php
declare(strict_types=1);

/**
 * KRIS 2 CMS - Professional UI Edition (Secured)
 * File: /editor/index.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/auth.php';

// I file interni del pannello controllano questa costante: aperti
// direttamente via URL si fermano invece di eseguire qualcosa.
define('KRIS_EDITOR', true);

require_once __DIR__ . '/helpers.php';

use Kris\Entity\StorageException;

// Cookie di sessione non leggibile da JavaScript e non inviato cross-site.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

// Il token CSRF viene inserito automaticamente in ogni form POST della pagina.
ob_start('kris_inject_csrf');

// --- SISTEMA DI LOGIN (GATEKEEPER) ---

// Logout
if (isset($_GET['logout'])) {
    kris_logout();
    header("Location: index.php");
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
            header("Location: index.php");
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
        header("Location: index.php");
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
$images = glob($uploadDir . '*.{jpg,png,svg,webp,jpeg,gif}', GLOB_BRACE);

// Percorso della cartella dell'editor, per i file statici.
$assetBase = htmlspecialchars(rtrim(dirname($_SERVER['PHP_SELF']), '/'));
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="kris-csrf" content="<?= htmlspecialchars(kris_csrf_token()) ?>">
    <title>Kris 2 CMS</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
    <link rel="stylesheet" href="<?= $assetBase ?>/css/style.css">
</head>

<body>

    <?php require __DIR__ . '/partials/sidebar.php'; ?>

    <main>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($msg): ?>
            <div class="alert">
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
            'structure'        => 'structure.php',
            'structure_impact' => 'structure_impact.php',
            'edit'             => 'edit.php',
            'media'            => 'media.php',
            'settings'         => 'settings.php',
        ];
        require __DIR__ . '/views/' . ($views[$action] ?? $views['dashboard']);
        ?>
    </main>

    <?php require __DIR__ . '/partials/media_overlay.php'; ?>

    <script src="<?= $assetBase ?>/js/scripts.js"></script>
    <?php if ($action === 'structure'): ?>
        <script src="<?= $assetBase ?>/js/structure.js"></script>
    <?php endif; ?>
</body>

</html>
