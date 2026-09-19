<?php
declare(strict_types=1);

/**
 * Bootstrap dei test: autoload, helper di costruzione entita e rendering,
 * e copia isolata del progetto per i test di pagina.
 *
 * Regola: i test non devono MAI leggere o scrivere data/. Usano le fixture
 * congelate in tests/fixtures/, cosi modificare i contenuti del sito non
 * rompe la suite.
 */

define('KRIS_ROOT', dirname(__DIR__));
define('KRIS_TESTS', __DIR__);

require_once KRIS_ROOT . '/vendor/autoload.php';

use Kris\Entity\Entity;
use Kris\Template\TemplateEngine;

/** Entita costruita al volo, senza toccare il filesystem. */
function makeEntity(array $fields, int $id = 0, string $name = 'test'): Entity
{
    return Entity::fromArray(['id' => $id, 'data' => $fields], $name);
}

function fieldPlain(string $name, string $value): array
{
    return ['name' => $name, 'type' => 'plain', 'value' => $value];
}

function fieldText(string $name, array $translations): array
{
    return ['name' => $name, 'type' => 'text', 'value' => $translations];
}

/** Rende un template al volo (senza file) contro un'entita costruita a mano. */
function renderTemplate(string $html, Entity $entity, string $lang = 'it'): string
{
    return (new TemplateEngine($lang))->render($html, $entity);
}

/**
 * Copia isolata del progetto con le fixture al posto di data/.
 * Creata una volta per esecuzione e riusata da tutti i test di pagina.
 */
function projectSandbox(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $dir = sys_get_temp_dir() . '/kris-tests-' . getmypid();
    if (is_dir($dir)) rrmdir($dir);
    mkdir($dir, 0777, true);

    foreach (['index.php', '404.php'] as $file) {
        copy(KRIS_ROOT . '/' . $file, $dir . '/' . $file);
    }
    foreach (['core', 'template', 'config', 'vendor'] as $folder) {
        rcopy(KRIS_ROOT . '/' . $folder, $dir . '/' . $folder);
    }
    rcopy(KRIS_TESTS . '/fixtures', $dir . '/data');

    register_shutdown_function(fn() => rrmdir($dir));
    return $dir;
}

/**
 * Rende una pagina pubblica come farebbe una richiesta HTTP, in un processo
 * separato: un errore fatale nella pagina non deve uccidere la suite.
 *
 * @return array{output: string, status: int, fatal: ?string}
 */
function renderPage(array $query): array
{
    $sandbox = projectSandbox();
    $harness = KRIS_TESTS . '/harness/render.php';

    $cmd = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($harness));

    // I parametri passano dall'environment: su Windows escapeshellarg()
    // sostituisce i '%' con spazi e corromperebbe i valori urlencoded.
    $env = getenv();
    $env['KRIS_QUERY'] = http_build_query($query);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $sandbox, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('impossibile avviare il processo di rendering');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $p) fclose($p);
    proc_close($proc);

    $fatal = null;
    if (preg_match('/(PHP )?Fatal error:.*/', $stderr, $m)) {
        $fatal = trim($m[0]);
    }

    // L'harness stampa lo stato come prima riga: "KRIS-STATUS: 200"
    $status = 200;
    if (preg_match('/^KRIS-STATUS: (\d+)\n/', $stdout, $m)) {
        $status = (int) $m[1];
        $stdout = substr($stdout, strlen($m[0]));
    }

    return ['output' => $stdout, 'status' => $status, 'fatal' => $fatal];
}

/** Confronto con un golden file; con --update-snapshots lo riscrive. */
function assertMatchesSnapshot(string $name, string $actual): void
{
    $file = KRIS_TESTS . '/snapshots/' . $name . '.html';

    if (!empty($GLOBALS['kris_update_snapshots']) || !file_exists($file)) {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
        file_put_contents($file, $actual);
        if (empty($GLOBALS['kris_update_snapshots'])) {
            fail("snapshot '{$name}' non esisteva ed e stato creato: rilancia la suite per confrontarlo");
        }
        return;
    }

    $expected = file_get_contents($file);
    if ($expected === $actual) return;

    // Prima riga che differisce, per un messaggio leggibile.
    $e = explode("\n", $expected);
    $a = explode("\n", $actual);
    $line = 0;
    while ($line < max(count($e), count($a)) && ($e[$line] ?? null) === ($a[$line] ?? null)) $line++;

    fail(sprintf(
        "snapshot '%s' diverso alla riga %d\n   atteso:   %s\n   ottenuto: %s",
        $name,
        $line + 1,
        var_export(trim($e[$line] ?? '(fine file)'), true),
        var_export(trim($a[$line] ?? '(fine file)'), true)
    ));
}

// --- utility filesystem -------------------------------------------------

function rcopy(string $src, string $dst): void
{
    if (!is_dir($dst)) mkdir($dst, 0777, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $from = $src . '/' . $item;
        $to   = $dst . '/' . $item;
        is_dir($from) ? rcopy($from, $to) : copy($from, $to);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
