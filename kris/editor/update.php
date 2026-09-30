<?php
declare(strict_types=1);

/**
 * Operazioni di aggiornamento del framework, chiamate dalla scheda
 * "Versione di Kris" in Impostazioni. Risponde sempre JSON.
 *
 * op: check | prepare | upload | install | rollback | recover
 *
 * Nell'operazione install il framework viene sostituito mentre questa
 * richiesta e in corso: prima di passare il controllo all'installer qui si
 * usano solo Updater, Package, Signature e Http (vedi Updater).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/auth.php';

use Kris\Update\SiteState;
use Kris\Update\Updater;
use Kris\Update\UpdateException;

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function kris_update_respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!kris_is_logged_in()) {
    kris_update_respond(['ok' => false, 'error' => 'Sessione scaduta: ricarica la pagina e accedi di nuovo.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kris_csrf_valid($_POST['csrf'] ?? null)) {
    kris_update_respond(['ok' => false, 'error' => 'Richiesta non valida: ricarica la pagina e riprova.'], 419);
}

$op = is_string($_POST['op'] ?? null) ? $_POST['op'] : '';
$user = (string) ($_SESSION['kris_user'] ?? '');
// Le operazioni possono durare: la sessione non deve bloccare le altre richieste.
session_write_close();

$updater = new Updater();
$maintenance = kris_maintenance();
if ($maintenance !== null && $op !== 'recover') {
    kris_update_respond(['ok' => false, 'error' => $maintenance['stale']
        ? 'Un aggiornamento precedente si è interrotto: prima ripristina il sito.'
        : 'C\'è già un aggiornamento in corso. Attendi qualche minuto.']);
}

// Un'operazione alla volta, anche con due admin collegati.
$lock = null;
if ($op !== 'check') {
    $dir = KRIS_ROOT . '/data/updates';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        kris_update_respond(['ok' => false, 'error' => 'C\'è già un\'operazione di aggiornamento in corso.']);
    }
}

try {
    switch ($op) {
        case 'check':
            try {
                $result = $updater->check();
            } catch (UpdateException $e) {
                kris_record_check(null, $e->getMessage());
                throw $e;
            }
            kris_record_check($result['target']['version'] ?? null, null);
            kris_update_respond(['ok' => true] + kris_present_check($result));

        case 'prepare':
            $prepared = $updater->prepareFromChannel();
            kris_update_respond(['ok' => true, 'prepared' => $prepared]);

        case 'upload':
            $file = $_FILES['package'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new UpdateException('Il pacchetto non è arrivato: controlla il file e riprova (il limite di caricamento dell\'hosting potrebbe essere troppo basso).');
            }
            $prepared = $updater->prepareFromZip($file['tmp_name'], 'caricato a mano');
            kris_update_respond(['ok' => true, 'prepared' => $prepared]);

        case 'install':
            $result = $updater->installPrepared($user);
            kris_update_respond($result);

        case 'rollback':
            $snapshot = $updater->availableRollback();
            if ($snapshot === null) {
                throw new UpdateException('Non c\'è più una versione precedente a cui tornare.');
            }
            kris_update_respond($updater->rollback((string) $snapshot['id'], $user));

        case 'recover':
            kris_update_respond($updater->recover($user));

        default:
            kris_update_respond(['ok' => false, 'error' => 'Operazione sconosciuta.'], 400);
    }
} catch (UpdateException $e) {
    kris_update_respond(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Kris update: ' . $e);
    kris_update_respond(['ok' => false, 'error' => 'Errore imprevisto durante l\'operazione. Il sito non è stato modificato; se si ripete, contatta lo sviluppatore.']);
}

/** Salva l'esito dell'ultimo controllo nello stato del sito. */
function kris_record_check(?string $latest, ?string $error): void
{
    try {
        $state = SiteState::load();
        $state['last_check'] = ['at' => date('c'), 'latest' => $latest, 'error' => $error];
        SiteState::save($state);
    } catch (Throwable) {
        // lo stato e informativo: il controllo vale comunque
    }
}

/** Solo cio che serve alla pagina, con i testi per il cliente. */
function kris_present_check(array $result): array
{
    $notes = [];
    $until = $result['target']['version'] ?? null;
    foreach ($result['newer'] as $release) {
        if ($until !== null && version_compare($release['version'], $until, '<=')) {
            $notes[] = [
                'version' => $release['version'],
                'date' => (string) ($release['date'] ?? ''),
                'changelog' => (string) ($release['changelog_it'] ?? ''),
            ];
        }
    }
    return [
        'current' => $result['current'],
        'target' => $until,
        'blocked' => $result['blocked']['version'] ?? null,
        'unmet' => $result['unmet'],
        'notes' => $notes,
    ];
}
