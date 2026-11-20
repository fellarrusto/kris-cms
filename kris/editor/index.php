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

// Primo avvio: nessun utente, si crea l'amministratore.
if (!kris_users()->exists()) {
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
        } elseif (!Kris\Auth\Users::validAddress($email)) {
            kris_render_setup('Serve un indirizzo email valido: lo userai per recuperare la password.');
        } elseif ($pass !== (string) ($_POST['pass2'] ?? '')) {
            kris_render_setup('Le due password non coincidono.');
        } else {
            try {
                kris_create_first_admin($user, $pass, $email);
            } catch (Kris\Auth\UserException $e) {
                kris_render_setup($e->getMessage());
            }
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
            $sent = kris_send_reset_link((string) ($_POST['email'] ?? ''));
        } catch (RuntimeException $e) {
            kris_render_forgot($e->getMessage());
        }
        if ($sent === 'limit') {
            kris_render_forgot('Hai già chiesto diversi link nell\'ultima ora. Controlla la posta (anche lo spam) o riprova più tardi.');
        }
        if ($sent === 'failed') {
            kris_render_forgot('L\'hosting non è riuscito a inviare l\'email. ' . KRIS_HOSTING_HELP);
        }
        // Stessa risposta se l'email non è di nessuno: non si scopre quali esistono
        kris_render_forgot('', 'Se l\'email è di un utente del sito, riceverà un link valido 30 minuti. Controlla anche lo spam.');
    }
    if (isset($_GET['forgot'])) {
        kris_render_forgot();
    }
    if ($isPost && isset($_POST['do_reset'])) {
        $token = (string) ($_POST['token'] ?? '');
        $pass = (string) ($_POST['pass'] ?? '');
        if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
            kris_render_reset($token, 'Sessione scaduta: riprova.');
        } elseif (strlen($pass) < Kris\Auth\Users::MIN_PASSWORD) {
            kris_render_reset($token, 'La password deve avere almeno ' . Kris\Auth\Users::MIN_PASSWORD . ' caratteri.');
        } elseif ($pass !== (string) ($_POST['pass2'] ?? '')) {
            kris_render_reset($token, 'Le due password non coincidono.');
        } elseif (($resetUser = kris_password_reset()->consume($token)) === null) {
            kris_render_reset($token);
        } else {
            try {
                kris_users()->setPassword($resetUser, $pass);
            } catch (Kris\Auth\UserException $e) {
                kris_render_login($e->getMessage() . ' ' . KRIS_HOSTING_HELP);
            }
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
    } else {
        $result = kris_login((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''));
        if ($result === 'ok') {
            header("Location: $BASE");
            exit;
        }
        $login_error = $result === 'blocked'
            ? 'Il tuo accesso è sospeso: chiedi a un amministratore del sito.'
            : 'Nome utente o password non validi.';
    }
}

// L API risponde in JSON anche a chi non ha la sessione: l app torna all accesso
if (isset($_GET['api']) && !kris_is_logged_in()) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Sessione scaduta: accedi di nuovo.']);
    exit;
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

// --- API JSON (app Vue) ---
if (isset($_GET['api'])) require __DIR__ . '/api.php';

// Il popup dell'anteprima approva il codice in attesa con un modulo e torna alla stessa pagina
if ($isPost && isset($_POST['approve_code'])) {
    if (kris_csrf_valid($_POST['csrf'] ?? null) && $editorLock === '') {
        try {
            $stagingNav->approveCode((string) $_POST['approve_code'], (string) ($_SESSION['kris_user'] ?? ''));
        } catch (\Kris\Staging\StagingException) {
            // Il codice è cambiato nel frattempo: il popup ricompare con la versione nuova
        }
    }
    $return = (string) ($_POST['return'] ?? '');
    $siteBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    header('Location: ' . (preg_match('/^[A-Za-z0-9_=&%.\/-]*kris_preview=1[A-Za-z0-9_=&%.\/-]*$/', $return) === 1 ? $siteBase . '/index.php?' . $return : $BASE . '#/da-pubblicare'));
    exit;
}

// Indirizzi dell'editor della 1.x (?action=...): le stesse pagine nell'app
if (isset($_GET['action'])) {
    $routes = ['staging' => 'da-pubblicare', 'media' => 'libreria', 'settings' => 'impostazioni', 'structure' => 'struttura'];
    header('Location: ' . $BASE . '#/' . ($routes[$_GET['action']] ?? ''));
    exit;
}

// --- APP VUE ---
// L'editor è l'app compilata in kris/editor/dist/ (sorgenti in admin-src/, npm run build): questa pagina la
// carica con il token e gli indirizzi. Le pagine di accesso restano in PHP, qui sopra.
$manifest = json_decode((string) @file_get_contents(__DIR__ . '/dist/manifest.json'), true);
$entry = is_array($manifest) ? ($manifest['src/main.js'] ?? null) : null;
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Editor non disponibile · Kris</title></head><body style="font-family:system-ui,sans-serif;padding:32px">'
        . '<h1 style="font-weight:500">L\'editor non è completo</h1><p>Manca kris/editor/dist/: ricarica la cartella kris/ intera della versione di Kris installata.</p></body></html>';
    exit;
}
$distBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/kris/editor/dist/';
$boot = ['csrf' => kris_csrf_token(), 'api' => 'index.php', 'assets' => $distBase];
echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Kris</title>'
    . '<script>try{var t=localStorage.getItem(\'kris-theme\');if(t===\'light\'||t===\'dark\')document.documentElement.dataset.theme=t;}catch(e){}</script>';
foreach ((array) ($entry['css'] ?? []) as $css) echo '<link rel="stylesheet" href="' . h($distBase . $css) . '">';
// I moduli dei plugin importano "vue" e "@kris/editor": gli stessi file dell'editor, quindi la stessa istanza
$imports = [];
foreach (['vue' => 'src/vue.js', '@kris/editor' => 'src/kit.js'] as $specifier => $source) {
    if (is_string($manifest[$source]['file'] ?? null)) $imports[$specifier] = $distBase . $manifest[$source]['file'];
}
if ($imports) echo '<script type="importmap">' . json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" integrity="sha512-6JR4bbn8rCKvrkdoTJd/VFyXAN4CE9XMtgykPWgKiHjou56YDJxWsi90hAeMTYxNwUnKSQu9JPc3SQUg+aGCHw==" crossorigin="anonymous" referrerpolicy="origin" defer></script>'
    . '</head><body><noscript>L\'editor di Kris ha bisogno di JavaScript.</noscript><div id="app"></div>'
    . '<script>window.KRIS=' . json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
    . '<script type="module" src="' . h($distBase . $entry['file']) . '"></script></body></html>';
