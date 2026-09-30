<?php
declare(strict_types=1);

/**
 * Esegue un'operazione di aggiornamento su un sito di prova, in un processo
 * isolato: l'installazione sostituisce kris/ e puo terminare il processo.
 * Va eseguito con la working directory sulla copia del sito.
 *
 * Environment: KRIS_UPDATE_OP (install | prepare | rollback | recover),
 *              KRIS_UPDATE_ZIP, KRIS_UPDATE_KEY (chiave pubblica di test).
 *
 * Stampa:  KRIS-RESULT: <json>\n<eventuale altro output>
 */

$root = str_replace('\\', '/', getcwd());
require $root . '/kris/bootstrap.php';

$op = (string) getenv('KRIS_UPDATE_OP');
$zip = (string) getenv('KRIS_UPDATE_ZIP');
$key = (string) getenv('KRIS_UPDATE_KEY');
$result = null;

ob_start();
register_shutdown_function(function () use (&$result): void {
    $other = (string) ob_get_clean();
    echo 'KRIS-RESULT: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n" . $other;
});

$updater = new Kris\Update\Updater($root, $root . '/kris', [$key], null, [
    'enabled' => true,
    'channel' => 'https://raw.githubusercontent.com/test/test/main/releases.json',
]);

try {
    switch ($op) {
        case 'prepare':
            $result = ['ok' => true, 'prepared' => $updater->prepareFromZip($zip, 'test')];
            break;
        case 'install':
            $updater->prepareFromZip($zip, 'test');
            $result = $updater->installPrepared('tester');
            break;
        case 'rollback':
            $snapshot = $updater->availableRollback();
            $result = $snapshot ? $updater->rollback((string) $snapshot['id'], 'tester') : ['ok' => false, 'error' => 'nessuno snapshot'];
            break;
        case 'recover':
            $result = $updater->recover('tester');
            break;
    }
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => $e->getMessage(), 'exception' => get_class($e)];
}
