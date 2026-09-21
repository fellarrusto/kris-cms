<?php
declare(strict_types=1);

/**
 * Kris 2 CMS — runner dei test, senza dipendenze esterne.
 *
 * Uso:
 *   php tests/run.php                      esegue tutte le suite
 *   php tests/run.php template             esegue solo le suite che contengono "template"
 *   php tests/run.php --update-snapshots   rigenera i golden file (usare consapevolmente)
 *
 * Esiti:
 *   PASS   comportamento corretto e verificato
 *   FAIL   regressione: qualcosa che funzionava non funziona più
 *   XFAIL  bug noto, gia documentato nel piano: il test e rosso di proposito
 *   XPASS  un bug noto risulta risolto -> togli xfail() e trasformalo in test()
 */

const T_PASS = 'PASS', T_FAIL = 'FAIL', T_XFAIL = 'XFAIL', T_XPASS = 'XPASS';

$GLOBALS['kris_tests'] = [];
$GLOBALS['kris_suite'] = '';

/** Test di un comportamento che deve funzionare: se fallisce e una regressione. */
function test(string $name, callable $fn): void
{
    $GLOBALS['kris_tests'][] = ['suite' => $GLOBALS['kris_suite'], 'name' => $name, 'fn' => $fn, 'xfail' => null];
}

/**
 * Test di un comportamento corretto che oggi NON funziona (bug noto).
 * $ref e l'identificativo dell'intervento nel piano (es. "A3.3").
 */
function xfail(string $ref, string $name, callable $fn): void
{
    $GLOBALS['kris_tests'][] = ['suite' => $GLOBALS['kris_suite'], 'name' => $name, 'fn' => $fn, 'xfail' => $ref];
}

final class AssertionFailed extends Exception {}

function fail(string $message): void
{
    throw new AssertionFailed($message);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(sprintf(
            "%satteso: %s\n   ottenuto: %s",
            $message ? $message . "\n   " : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $cond, string $message = 'condizione falsa'): void
{
    if (!$cond) fail($message);
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message ? $message . "\n   " : '') . "non trovato: " . var_export($needle, true)
            . "\n   dentro (primi 200): " . var_export(substr($haystack, 0, 200), true));
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($message ? $message . "\n   " : '') . "trovato ma non atteso: " . var_export($needle, true));
    }
}

// --- esecuzione ---------------------------------------------------------

require_once __DIR__ . '/bootstrap.php';

$args = array_slice($argv, 1);
$updateSnapshots = in_array('--update-snapshots', $args, true);
$filter = implode(' ', array_filter($args, fn($a) => !str_starts_with($a, '--')));
$GLOBALS['kris_update_snapshots'] = $updateSnapshots;

foreach (glob(__DIR__ . '/suites/*_test.php') as $suiteFile) {
    $GLOBALS['kris_suite'] = basename($suiteFile, '_test.php');
    require_once $suiteFile;
}

$counts = [T_PASS => 0, T_FAIL => 0, T_XFAIL => 0, T_XPASS => 0];
$problems = [];
$currentSuite = null;

foreach ($GLOBALS['kris_tests'] as $t) {
    if ($filter !== '' && !str_contains($t['suite'] . ' ' . $t['name'], $filter)) continue;

    if ($t['suite'] !== $currentSuite) {
        $currentSuite = $t['suite'];
        echo "\n" . strtoupper($currentSuite) . "\n";
    }

    $error = null;
    try {
        ($t['fn'])();
    } catch (Throwable $e) {
        $error = $e instanceof AssertionFailed
            ? $e->getMessage()
            : get_class($e) . ': ' . $e->getMessage();
    }

    if ($t['xfail'] !== null) {
        $status = $error === null ? T_XPASS : T_XFAIL;
        $label  = $status === T_XPASS
            ? sprintf('  XPASS  %-58s [%s RISOLTO]', $t['name'], $t['xfail'])
            : sprintf('  xfail  %-58s [%s]', $t['name'], $t['xfail']);
        if ($status === T_XPASS) {
            $problems[] = "XPASS  {$t['suite']}: {$t['name']}\n   Il bug {$t['xfail']} risulta risolto: sostituisci xfail() con test().";
        }
    } else {
        $status = $error === null ? T_PASS : T_FAIL;
        $label  = sprintf('  %-6s %s', $status === T_PASS ? 'ok' : 'FAIL', $t['name']);
        if ($status === T_FAIL) {
            $problems[] = "FAIL   {$t['suite']}: {$t['name']}\n   " . str_replace("\n", "\n   ", (string) $error);
        }
    }

    $counts[$status]++;
    echo $label . "\n";
}

echo "\n" . str_repeat('-', 72) . "\n";
printf(
    "%d ok, %d falliti, %d bug noti (xfail), %d risolti da promuovere (xpass)\n",
    $counts[T_PASS], $counts[T_FAIL], $counts[T_XFAIL], $counts[T_XPASS]
);

if ($problems) {
    echo "\n" . implode("\n\n", $problems) . "\n";
}

// Rosso solo per regressioni vere o bug risolti da promuovere: gli xfail sono attesi.
exit($counts[T_FAIL] > 0 || $counts[T_XPASS] > 0 ? 1 : 0);
