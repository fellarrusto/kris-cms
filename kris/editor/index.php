<?php
declare(strict_types=1);

/**
 * L'editor di Kris: accesso, stato del framework, modalità, azioni e viste.
 * Servito dallo stub kris-admin/index.php.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/auth.php';

// I file interni del pannello controllano questa costante: aperti
// direttamente via URL si fermano invece di eseguire qualcosa.
define('KRIS_EDITOR', true);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/model.php';

use Kris\Entity\StorageException;
use Kris\Update\Migrator;
use Kris\Update\SiteState;
use Kris\Update\UpdateException;

// Cookie di sessione non leggibile da JavaScript e non inviato cross-site.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => kris_is_https(),
]);
session_start();
kris_security_headers();

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
    // Chi crea l'accesso deve dimostrare di avere i file del sito: il codice sta in config/setup_code.php
    if (kris_setup_code()->ensure() === null) {
        kris_render_setup('Non riesco a scrivere config/setup_code.php: controlla i permessi della cartella config/.');
    }
    if ($isPost && isset($_POST['do_setup'])) {
        $user = trim((string) ($_POST['user'] ?? ''));
        $pass = (string) ($_POST['pass'] ?? '');
        $email = trim((string) ($_POST['email'] ?? ''));
        $wait = kris_login_wait();
        if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
            kris_render_setup('Sessione scaduta: riprova.');
        } elseif ($wait > 0) {
            kris_render_setup('Troppi tentativi sbagliati. Riprova tra ' . max(1, (int) ceil($wait / 60)) . ' minuti.');
        } elseif (!kris_setup_code()->verify((string) ($_POST['setup_code'] ?? ''))) {
            kris_login_throttle()->fail((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            kris_render_setup('Il codice non corrisponde a quello in config/setup_code.php.');
        } elseif ($user === '' || strlen($pass) < Kris\Auth\Credentials::MIN_PASSWORD) {
            kris_render_setup('Serve uno username e una password di almeno 10 caratteri.');
        } elseif (!Kris\Auth\Credentials::validEmail($email)) {
            kris_render_setup('Serve un indirizzo email valido: lo userai per recuperare la password.');
        } elseif ($pass !== (string) ($_POST['pass2'] ?? '')) {
            kris_render_setup('Le due password non coincidono.');
        } elseif (!kris_save_credentials($user, $pass, $email)) {
            kris_render_setup('Non riesco a scrivere config/auth.php: controlla i permessi della cartella.');
        } else {
            kris_setup_code()->remove();
            header("Location: $BASE");
            exit;
        }
    }
    kris_render_setup();
}

// Recupero della password: richiesta del link e scelta della nuova password.
if (!kris_is_logged_in()) {
    if ($isPost && isset($_POST['do_forgot'])) {
        if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
            kris_render_forgot('Sessione scaduta: riprova.');
        }
        try {
            if (!kris_password_reset()->canRequest()) {
                kris_render_forgot('Hai già chiesto diversi link nell\'ultima ora. Controlla la posta (anche lo spam) o riprova più tardi.');
            }
            if (!kris_send_reset_link()) {
                kris_render_forgot('L\'hosting non è riuscito a inviare l\'email. ' . KRIS_HOSTING_HELP);
            }
        } catch (RuntimeException $e) {
            kris_render_forgot($e->getMessage());
        }
        kris_render_forgot('', 'Email inviata all\'amministratore del sito: contiene un link valido 30 minuti. Controlla anche lo spam.');
    }
    if (isset($_GET['forgot'])) {
        kris_render_forgot();
    }
    if ($isPost && isset($_POST['do_reset'])) {
        $token = (string) ($_POST['token'] ?? '');
        $pass = (string) ($_POST['pass'] ?? '');
        if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
            kris_render_reset($token, 'Sessione scaduta: riprova.');
        } elseif (strlen($pass) < Kris\Auth\Credentials::MIN_PASSWORD) {
            kris_render_reset($token, 'La password deve avere almeno 10 caratteri.');
        } elseif ($pass !== (string) ($_POST['pass2'] ?? '')) {
            kris_render_reset($token, 'Le due password non coincidono.');
        } elseif (!kris_password_reset()->consume($token)) {
            kris_render_reset($token);
        } elseif (!kris_credentials()->save(['password' => $pass])) {
            kris_render_reset($token, 'Non è stato possibile salvare la nuova password. ' . KRIS_HOSTING_HELP);
        }
        kris_render_login('', 'Password aggiornata: accedi con quella nuova.');
    }
    if (is_string($_GET['reset'] ?? null)) {
        kris_render_reset($_GET['reset']);
    }
}

// Login
if ($isPost && isset($_POST['do_login'])) {
    $wait = kris_login_wait();
    if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
        $login_error = "Sessione scaduta: riprova.";
    } elseif ($wait > 0) {
        // La password non viene nemmeno verificata: niente tentativi in più
        $login_error = 'Troppi tentativi sbagliati. Riprova tra ' . max(1, (int) ceil($wait / 60)) . ' minuti.';
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
                \Kris\Update\Stubs::ensure(KRIS_ROOT);
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

// Aggiornamenti suggeriti: il canale si legge al massimo una volta al giorno, dal browser dopo il caricamento;
// nel frattempo vale l'esito dell'ultimo controllo. Mai durante un blocco o nella scheda della versione.
$updateHint = null;
if ($editorLock === '' && ($_GET['action'] ?? '') !== 'settings' && isset($siteState) && \Kris\Update\Updater::loadConfig(KRIS_ROOT)['enabled']) {
    $lastCheck = is_array($siteState['last_check'] ?? null) ? $siteState['last_check'] : [];
    $latest = is_string($lastCheck['latest'] ?? null) && version_compare($lastCheck['latest'], $krisVersion, '>') ? $lastCheck['latest'] : '';
    $updateHint = ['latest' => $latest, 'check' => (int) strtotime((string) ($lastCheck['at'] ?? '')) < time() - 86400];
}


// --- MODALITÀ: SITO PUBBLICO O IN ATTESA ---
// In «In attesa» l'editor mostra lo staging e ogni salvataggio diventa
// un'operazione dello staging: il sito cambia solo alla pubblicazione.
if ($isPost && isset($_POST['editing_mode']) && kris_csrf_valid($_POST['csrf'] ?? null)) {
    $_SESSION['kris_editing'] = $_POST['editing_mode'] === 'staging' ? 'staging' : 'live';
    $back = is_string($_POST['return_to'] ?? null) && preg_match('/^\?action=[a-z_]+(&[A-Za-z0-9_=%.\/-]*)*$/', $_POST['return_to'])
        ? $_POST['return_to'] : '?action=dashboard';
    header("Location: $BASE$back");
    exit;
}
$stagingMode = ($_SESSION['kris_editing'] ?? '') === 'staging';
$stagingNav = new \Kris\Staging\Staging();

// --- DATI ---
try {
    editorReload($stagingMode);
    // I file caricati in attesa si vedono attraverso l'anteprima, in entrambe le modalità
    editorStagedUploads(array_map('basename', array_filter($stagingNav->uploadFiles(), fn($f) => !is_file($uploadDir . basename($f)))));
} catch (StorageException | \Kris\Store\StoreException $e) {
    renderStorageError($e->getMessage());
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'dashboard';
$group = is_string($_GET['group'] ?? null) ? $_GET['group'] : null;
$msg = '';
$error = '';

// --- AZIONI POST ---
require __DIR__ . '/actions.php';

$activeLangs = editorLanguages();
$userTypes = editorUserTypes();
$counts = [];
foreach (editorSite()['content'] as $item) $counts[$item['type']] = ($counts[$item['type']] ?? 0) + 1;
$images = editorMediaFiles($uploadDir);
if ($stagingMode) {
    // I file in attesa vengono prima: sono quelli appena caricati
    $images = array_merge($stagingNav->uploadFiles(), $images);
}
if (isset($_GET['upload_error']) && is_string($_GET['upload_error'])) $error = $_GET['upload_error'];
if (in_array($action, ['edit', 'list'], true) && ($group === null || editorType($group) === null)) {
    $action = 'dashboard';
    $error = 'Questa raccolta non c\'è più. Scegli uno dei contenuti qui sotto.';
}
if ($action === 'structure' && $group !== null && editorType($group) === null) $group = null;
// Un contenuto unico non ha una lista: si apre direttamente
if ($action === 'list' && $group !== null && isSingleCollection($group)) {
    $only = editorItems($group)[0] ?? null;
    if ($only !== null) {
        $action = 'edit';
        $_GET['id'] = (string) (int) $only['id'];
        $_GET['path'] = '';
    }
}
// Le sezioni di Kris hanno un solo contenuto e uno schema fisso
if (isReservedCollection($group) && in_array($action, ['list', 'structure', 'structure_impact'], true)) {
    $action = 'edit';
    $_GET['id'] = '0';
    $_GET['path'] = '';
}
// La struttura si cambia solo sul sito pubblico
if ($stagingMode && in_array($action, ['structure', 'structure_impact'], true)) {
    $action = $group !== null && editorType($group) !== null ? 'list' : 'dashboard';
    $error = 'La struttura si cambia sul sito pubblico: passa a «Sito pubblico» per modificarla.';
}
$reservedViews = ['kris_site' => 'Proprietà del sito', 'kris_legal' => 'Privacy e cookie', 'kris_notfound' => 'Pagina 404', 'kris_wip' => 'Lavori in corso'];
$inReserved = $action === 'edit' && isset($reservedViews[$group ?? '']) ? $group : null;
// «Da pubblicare» compare con modifiche in attesa o con una pubblicazione da poter annullare
$stagingCount = count($stagingNav->changes()) + count($stagingNav->operations()) + count($stagingNav->manifest()['uploads']) + count($stagingNav->manifest()['fonts']);
$stagingVisible = $stagingCount > 0 || $action === 'staging' || $stagingNav->lastPromotion() !== null;
$sectionLabel = match (true) {
    $action === 'media' => 'Libreria media',
    $action === 'settings' => 'Impostazioni',
    $action === 'staging' => 'Da pubblicare',
    $inReserved !== null => $reservedViews[$inReserved],
    in_array($action, ['structure', 'structure_impact'], true) => 'Struttura',
    default => 'Contenuti',
};
$siteName = editorText(editorItem('kris_site', 0)['values']['site_name'] ?? '');
$wipOn = (editorItem('kris_wip', 0)['values']['enabled'] ?? false) === true;

// I file statici dell'editor stanno in kris/editor/, la pagina è servita dallo stub in kris-admin/
$assetBase = h(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/kris/editor');
// La versione nell'indirizzo: dopo un aggiornamento il browser non riusa CSS e JS vecchi
$assetVersion = '?v=' . rawurlencode($krisVersion);
$returnTo = str_starts_with((string) ($_SERVER['QUERY_STRING'] ?? ''), 'action=') ? '?' . $_SERVER['QUERY_STRING'] : '?action=dashboard';
$navItem = function (string $href, string $icon, string $label, bool $on, string $count = '', string $class = '') {
    return '<a class="nav-item' . ($on ? ' is-active' : '') . ($class !== '' ? ' ' . $class : '') . '" href="' . h($href) . '"' . ($on ? ' aria-current="page"' : '') . '>'
        . uiIcon($icon) . '<span class="nav-text">' . h($label) . '</span>' . ($count !== '' ? '<span class="nav-count">' . h($count) . '</span>' : '') . '</a>';
};
$newestTypes = array_filter($userTypes, fn($t, $name) => isNewestFirst((string) $name), ARRAY_FILTER_USE_BOTH);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="kris-csrf" content="<?= h(kris_csrf_token()) ?>">
    <title><?= h($sectionLabel) ?> · Kris</title>
    <script>/* Tema scelto nell'editor, prima del CSS: niente lampo chiaro */try{var t=localStorage.getItem('kris-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t;}catch(e){}</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" integrity="sha512-6JR4bbn8rCKvrkdoTJd/VFyXAN4CE9XMtgykPWgKiHjou56YDJxWsi90hAeMTYxNwUnKSQu9JPc3SQUg+aGCHw==" crossorigin="anonymous" referrerpolicy="origin"></script>
    <link rel="stylesheet" href="<?= $assetBase ?>/css/style.css<?= $assetVersion ?>">
</head>
<body data-editor-action="<?= h($action) ?>"<?php if ($stagingMode): ?> data-staging-mode data-staged-uploads="<?= h(json_encode(array_keys(editorStagedUploads()))) ?>"<?php endif; ?>>
<a class="skip-link" href="#main">Vai al contenuto</a>
<div class="shell<?= $stagingMode ? ' is-pending' : '' ?>">
    <?php if ($stagingMode): ?>
    <div class="pending-bar" role="status"><?= uiIcon('clock') ?><p><strong>Stai lavorando in attesa.</strong> Il sito pubblico non cambia finché non pubblichi.<?php if ($stagingCount): ?> <?= $stagingCount ?> <?= $stagingCount === 1 ? 'modifica pronta' : 'modifiche pronte' ?>.<?php endif; ?></p><a class="btn btn-small" href="?action=staging">Rivedi e pubblica</a></div>
    <?php endif; ?>
    <nav class="side" id="sidebar" aria-label="Navigazione principale">
        <a class="brand" href="?action=dashboard"><span class="brand-mark" aria-hidden="true">K</span><span class="brand-name"><?= h($siteName !== '' ? $siteName : 'Il tuo sito') ?></span></a>
        <div class="nav-group">
            <?= $navItem('?action=dashboard', 'grid', 'Contenuti', in_array($action, ['dashboard', 'list', 'edit'], true) && $inReserved === null && !isset($newestTypes[$group ?? ''])) ?>
            <?= $navItem('?action=media', 'media', 'Libreria media', $action === 'media', (string) count($images)) ?>
        </div>
        <?php if ($stagingVisible): ?><div class="nav-group"><?= $navItem('?action=staging', 'publish', 'Da pubblicare', $action === 'staging', $stagingCount ? (string) $stagingCount : '', 'nav-publish' . ($stagingCount ? ' has-changes' : '')) ?></div><?php endif; ?>
        <?php if ($newestTypes): ?><div class="nav-group"><p class="nav-label">Raccolte</p><?php foreach ($newestTypes as $name => $_): ?><?= $navItem('?action=list&group=' . urlencode($name), 'posts', collectionLabel($name), $group === $name && in_array($action, ['list', 'edit'], true), (string) ($counts[$name] ?? 0)) ?><?php endforeach; ?></div><?php endif; ?>
        <div class="nav-group"><p class="nav-label">Sito</p>
            <?php foreach (['kris_site' => 'globe', 'kris_legal' => 'shield', 'kris_notfound' => 'notfound', 'kris_wip' => 'wip'] as $reserved => $icon): if (editorType($reserved) === null) continue; ?>
            <?= $navItem('?action=edit&group=' . $reserved . '&id=0', $icon, $reservedViews[$reserved], $inReserved === $reserved, $reserved === 'kris_wip' && $wipOn ? 'Attivi' : '') ?>
            <?php endforeach; ?>
        </div>
        <div class="nav-group"><p class="nav-label">Sviluppo</p>
            <?= $navItem('?action=structure' . ($group !== null && isset($userTypes[$group]) ? '&group=' . urlencode($group) : ''), 'code', 'Struttura', in_array($action, ['structure', 'structure_impact'], true)) ?>
        </div>
        <div class="nav-foot">
            <?= $navItem('?action=settings', 'settings', 'Impostazioni', $action === 'settings') ?>
            <button type="button" class="nav-item" data-theme-toggle><?= uiIcon('theme') ?><span class="nav-text" data-theme-label>Tema: automatico</span></button>
            <a class="nav-item" href="?logout=1"><?= uiIcon('logout') ?><span class="nav-text">Esci</span><span class="nav-count">Kris <?= h($krisVersion) ?></span></a>
        </div>
    </nav>
    <div class="side-scrim" data-menu-close hidden></div>
    <main class="main" id="main" tabindex="-1">
        <header class="top">
            <button type="button" class="icon-button menu-toggle" data-menu-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Apri il menu"><?= uiIcon('list') ?></button>
            <nav class="crumb" aria-label="Posizione"><a href="?action=dashboard"><?= h($sectionLabel) ?></a><?php if ($group !== null && editorType($group) !== null && $inReserved === null): ?><span aria-hidden="true">/</span><a href="?action=list&amp;group=<?= urlencode($group) ?>"><?= h(collectionLabel($group)) ?></a><?php endif; ?></nav>
            <form method="POST" class="seg mode-switch" role="group" aria-label="Dove salvano le modifiche">
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <button name="editing_mode" value="live" aria-pressed="<?= $stagingMode ? 'false' : 'true' ?>"><span class="dot" aria-hidden="true"></span>Sito pubblico</button>
                <button name="editing_mode" value="staging" aria-pressed="<?= $stagingMode ? 'true' : 'false' ?>">In attesa<?php if ($stagingCount): ?> <span class="seg-count"><?= $stagingCount ?></span><?php endif; ?></button>
            </form>
            <?php if ($stagingMode): ?>
                <a href="../index.php?kris_preview=1" target="_blank" rel="noopener" class="btn btn-white">Anteprima <?= uiIcon('external') ?></a>
            <?php else: ?>
                <a href="../index.php?kris_preview=0" target="_blank" rel="noopener" class="btn btn-white">Apri il sito <?= uiIcon('external') ?></a>
            <?php endif; ?>
        </header>
        <div id="request-feedback" class="alert alert-error" role="alert" hidden></div>
        <?php if ($editorLock !== ''): ?>
            <div class="alert alert-error" role="alert">
                <p><strong>Modifiche sospese.</strong> <?= h($editorLock) ?></p>
                <?php if ($migrationsPending): ?>
                    <form method="POST" class="alert-actions"><button class="btn btn-primary" name="run_migrations" value="1">Aggiorna il formato dei contenuti</button><small>Prima viene salvata una copia dei file dei contenuti.</small></form>
                <?php elseif (!empty($maintenance['stale']) && $action !== 'settings'): ?>
                    <a class="btn btn-white" href="?action=settings&amp;tab=versione">Vai alla versione di Kris</a>
                <?php endif; ?>
            </div>
        <?php elseif ($frameworkNotice !== ''): ?>
            <div class="alert" role="status"><p><?= h($frameworkNotice) ?></p></div>
        <?php endif; ?>
        <?php if ($updateHint !== null): ?>
            <div class="alert alert-info" role="status" data-update-banner data-latest="<?= h($updateHint['latest']) ?>"<?= $updateHint['check'] ? ' data-check' : '' ?> hidden><p><strong>È disponibile Kris <span data-update-version></span>.</strong> Prima di installarla puoi leggere le novità.</p><a class="btn btn-primary btn-small" href="?action=settings&amp;tab=versione">Aggiorna ora</a><button type="button" class="text-link" data-update-later>Più tardi</button></div>
        <?php endif; ?>
        <?php if ($wipOn && $inReserved !== 'kris_wip'): ?>
            <div class="alert alert-warning" role="status"><p><strong>Lavori in corso attivi.</strong> I visitatori vedono solo la pagina di lavorazione; tu vedi il sito perché hai l'editor aperto.</p><a class="btn btn-white btn-small" href="?action=edit&amp;group=kris_wip&amp;id=0">Lavori in corso</a></div>
        <?php endif; ?>
        <?php // Siti installati prima della 1.2.0: l'email di recupero si aggiunge da qui, in un passo
        $authConfig = kris_auth_config() ?? [];
        if (!Kris\Auth\Credentials::validEmail((string) ($authConfig['email'] ?? '')) && empty($_SESSION['kris_email_later']) && $editorLock === ''): ?>
            <div class="alert account-alert" role="status">
                <p><strong>Aggiungi la tua email.</strong> Se dimentichi la password, ti manderemo lì un link per sceglierne una nuova.</p>
                <form method="POST" class="inline-form" data-account-form><input type="hidden" name="save_account_email" value="1"><label class="sr-only" for="account-email-quick">Email</label><input class="input" id="account-email-quick" type="email" name="account_email" autocomplete="email" placeholder="nome@esempio.it" required><label class="sr-only" for="account-email-quick-password">Password attuale</label><input class="input" id="account-email-quick-password" type="password" name="current_password" autocomplete="current-password" placeholder="Password attuale" required><button class="btn btn-primary btn-small">Salva email</button></form>
                <form method="POST"><input type="hidden" name="account_email_later" value="1"><button class="text-link" type="submit">Più tardi</button></form>
            </div>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= uiIcon('warning') ?><p><?= h($error) ?></p></div><?php endif; ?>
        <?php if ($msg): ?><div class="alert alert-success" role="status"><?= uiIcon('check') ?><p><?= h($msg) ?></p></div><?php endif; ?>
        <?php
        // La vista si sceglie da una mappa esplicita, mai da un percorso preso dall'URL
        $views = [
            'dashboard' => 'dashboard.php',
            'list' => 'list.php',
            'structure' => 'structure.php',
            'structure_impact' => 'structure_impact.php',
            'edit' => 'edit.php',
            'media' => 'media.php',
            'settings' => 'settings.php',
            'staging' => 'staging.php',
        ];
        require __DIR__ . '/views/' . ($views[$action] ?? $views['dashboard']);
        ?>
    </main>
</div>
<?php require __DIR__ . '/partials/media_overlay.php'; ?>
<dialog id="confirmDialog" class="dialog confirm-dialog" aria-labelledby="confirmTitle">
    <div class="dialog-head"><h2 id="confirmTitle"></h2><button type="button" class="icon-button" data-close-dialog aria-label="Chiudi"><?= uiIcon('close') ?></button></div>
    <div class="dialog-body"><p id="confirmText"></p></div><div class="dialog-foot" id="confirmActions"></div>
</dialog>
<div id="toast" class="toast" role="status" hidden></div>
<script src="<?= $assetBase ?>/js/scripts.js<?= $assetVersion ?>"></script>
<?php if ($action === 'structure'): ?><script src="<?= $assetBase ?>/js/structure.js<?= $assetVersion ?>"></script><?php endif; ?>
<?php if ($action === 'settings'): ?><script src="<?= $assetBase ?>/js/update.js<?= $assetVersion ?>"></script><?php endif; ?>
</body>
</html>
