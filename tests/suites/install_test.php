<?php
declare(strict_types=1);

/**
 * Installazione vera su un sito di prova: pacchetto firmato, scambio di
 * kris/, migrazioni, verifica, e ritorno automatico allo stato precedente
 * in ogni modo di fallire che sappiamo provocare. Ogni test lavora su una
 * copia fresca del sito, in un processo separato.
 */

use Kris\Update\Signature;

if (!Signature::available() || !class_exists(ZipArchive::class)) {
    skipSuite('servono le estensioni PHP sodium e zip (php -d extension=sodium -d extension=zip tests/run.php)');
    return;
}

require_once KRIS_ROOT . '/tools/lib/release.php';

/** Chiavi di prova, uguali per tutta l'esecuzione. */
function testKeys(): array
{
    static $keys = null;
    return $keys ??= Signature::generateKeyPair();
}

/** Copia fresca del sito alla 1.0.0, che si fida solo della chiave di prova. */
function updateSite(): string
{
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/kris-site-' . getmypid() . '-' . uniqid();
    mkdir($dir, 0777, true);
    copy(KRIS_ROOT . '/index.php', $dir . '/index.php');
    foreach (['kris', 'template', 'config'] as $folder) {
        rcopy(KRIS_ROOT . '/' . $folder, $dir . '/' . $folder);
    }
    @unlink($dir . '/config/auth.php');
    @unlink($dir . '/config/update.php');
    rcopy(KRIS_TESTS . '/fixtures', $dir . '/data');
    foreach (['RELEASE.json', 'MANIFEST.json', 'MANIFEST.sig'] as $f) {
        @unlink($dir . '/kris/' . $f);
    }
    file_put_contents($dir . '/kris/VERSION', "1.0.0\n");
    file_put_contents($dir . '/kris/update/keys.php', '<?php return ' . var_export(['test' => testKeys()['public']], true) . ';');
    register_shutdown_function(fn() => rrmdir($dir));
    return $dir;
}

/**
 * Pacchetto costruito dal kris/ del sito di prova, con eventuali modifiche.
 * $change riceve la cartella kris/ copiata prima della firma.
 */
function updatePackage(string $site, string $version, ?callable $change = null, ?string $secret = null, array $release = []): string
{
    $src = sys_get_temp_dir() . '/kris-src-' . getmypid() . '-' . uniqid();
    rcopy($site . '/kris', $src);
    if ($change) {
        $change($src);
    }
    $zip = $src . '.zip';
    kris_build_package($src, $zip, $release + [
        'version' => $version, 'min_from' => '1.0.0', 'breaking' => false,
        'requires' => ['php' => '8.1', 'ext' => ['dom', 'json']], 'changelog_it' => "Prova {$version}",
    ], $secret ?? testKeys()['secret']);
    rrmdir($src);
    register_shutdown_function(fn() => @unlink($zip));
    return $zip;
}

/** Esegue un'operazione di aggiornamento nel sito, in un processo separato. */
function runUpdate(string $site, string $op, string $zip = ''): ?array
{
    $env = getenv();
    $env['KRIS_UPDATE_OP'] = $op;
    $env['KRIS_UPDATE_ZIP'] = $zip;
    $env['KRIS_UPDATE_KEY'] = testKeys()['public'];
    $proc = proc_open(phpCommand(KRIS_TESTS . '/harness/update.php'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $site, $env);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    foreach ($pipes as $p) fclose($p);
    proc_close($proc);
    return preg_match('/KRIS-RESULT: (.*)\n/', $out, $m) ? json_decode($m[1], true) : null;
}

function siteVersion(string $site): string
{
    return trim((string) file_get_contents($site . '/kris/VERSION'));
}

function siteState(string $site): array
{
    return json_decode((string) @file_get_contents($site . '/data/kris_state.json'), true) ?: [];
}

function lastOutcome(string $site): ?string
{
    $history = siteState($site)['history'] ?? [];
    return $history ? end($history)['outcome'] : null;
}

/** Controlli comuni a ogni aggiornamento annullato: sito identico a prima. */
function assertSiteUntouched(string $site, string $dataHashBefore): void
{
    assertSame('1.0.0', siteVersion($site), 'kris/ deve essere tornata alla 1.0.0');
    assertSame($dataHashBefore, md5_file($site . '/data/k_data.json'), 'i contenuti non devono cambiare');
    assertSame(false, is_file($site . '/data/kris_maintenance.json'), 'il sito non deve restare in manutenzione');
    $page = renderPage(['page' => 'homepage'], $site);
    assertSame(200, $page['status'], 'la homepage deve rispondere dopo il ripristino');
}

// --- percorso felice -------------------------------------------------------

test('installa una patch firmata e il sito funziona con la versione nuova', function () {
    $site = updateSite();
    $zip = updatePackage($site, '1.0.1');

    $r = runUpdate($site, 'install', $zip);
    assertSame(true, $r['ok'] ?? null, 'esito: ' . json_encode($r));
    assertSame('1.0.1', siteVersion($site));
    assertSame('1.0.1', siteState($site)['framework_version']);
    assertSame(1, siteState($site)['data_version']);
    assertSame('ok', lastOutcome($site));
    assertSame(false, is_file($site . '/data/kris_maintenance.json'));

    $snapshots = glob($site . '/data/snapshots/*/kris/VERSION');
    assertSame(1, count($snapshots), 'lo snapshot deve conservare il framework precedente');
    assertSame('1.0.0', trim(file_get_contents($snapshots[0])));

    $page = renderPage(['page' => 'homepage'], $site);
    assertSame(200, $page['status']);
    assertContains('<html', $page['output']);
});

test('torna alla versione precedente con contenuti e framework di allora', function () {
    $site = updateSite();
    runUpdate($site, 'install', updatePackage($site, '1.0.1'));
    assertSame('1.0.1', siteVersion($site));
    $dataAfterUpdate = file_get_contents($site . '/data/k_data.json');
    file_put_contents($site . '/data/k_data.json', str_replace('"id"', '"id" ', $dataAfterUpdate)); // modifica dopo l'update

    $r = runUpdate($site, 'rollback');
    assertSame(true, $r['ok'] ?? null, 'esito: ' . json_encode($r));
    assertSame('1.0.0', siteVersion($site));
    assertSame($dataAfterUpdate, file_get_contents($site . '/data/k_data.json'), 'i contenuti tornano a quelli dell\'aggiornamento');
    assertSame('manual_rollback', lastOutcome($site));
    assertSame(['ok', 'manual_rollback'], array_column(siteState($site)['history'], 'outcome'), 'il registro non perde l\'aggiornamento annullato');
    $history = siteState($site)['history'];
    $last = end($history);
    assertSame(['1.0.1', '1.0.0'], [$last['from'], $last['to']]);
    assertSame(1, count(glob($site . '/data/snapshots/*-annullato-*/kris') ?: []), 'lo stato scartato resta recuperabile');
    assertSame(200, renderPage(['page' => 'homepage'], $site)['status']);
});

test('una migrazione con modifiche ai dati viene applicata e registrata', function () {
    $site = updateSite();
    $zip = updatePackage($site, '1.1.0', function (string $kris) {
        file_put_contents($kris . '/migrations/0002_aggiunge_campo.php', <<<'PHP'
<?php
return ['version' => 2, 'description' => 'prova', 'up' => function (array $f): array {
    $f['k_model']['homepage'][] = ['name' => 'campo_nuovo', 'type' => 'plain'];
    return $f;
}];
PHP);
    });
    $r = runUpdate($site, 'install', $zip);
    assertSame(true, $r['ok'] ?? null, 'esito: ' . json_encode($r));
    assertSame(2, siteState($site)['data_version']);
    assertContains('campo_nuovo', file_get_contents($site . '/data/k_model.json'));
});

// --- guasti: il sito deve tornare com'era -----------------------------------

test('se una migrazione fallisce tutto torna com era', function () {
    $site = updateSite();
    $before = md5_file($site . '/data/k_data.json');
    $zip = updatePackage($site, '1.0.1', function (string $kris) {
        file_put_contents($kris . '/migrations/0002_rotta.php', "<?php\nreturn ['version' => 2, 'up' => function (array \$f): array { \$f['k_data'] = []; throw new RuntimeException('migrazione rotta'); }];\n");
    });
    $r = runUpdate($site, 'install', $zip);
    assertSame(false, $r['ok']);
    assertSame(true, $r['rolled_back']);
    assertContains('migrazione rotta', (string) $r['error']);
    assertSiteUntouched($site, $before);
    assertSame('rollback', lastOutcome($site));
    assertSame([], glob($site . '/data/snapshots/*') ?: [], 'uno snapshot consumato non resta');
});

test('se una pagina non si genera piu con il codice nuovo tutto torna com era', function () {
    $site = updateSite();
    $before = md5_file($site . '/data/k_data.json');
    $modelBefore = md5_file($site . '/data/k_model.json');
    $zip = updatePackage($site, '1.0.1', function (string $kris) {
        // La migrazione riesce e scrive i dati: il ripristino deve annullarla.
        file_put_contents($kris . '/migrations/0002_scrive.php', "<?php\nreturn ['version' => 2, 'up' => function (array \$f): array { \$f['k_model']['nuova'] = []; return \$f; }];\n");
        $file = $kris . '/core/template/TemplateEngine.php';
        $code = file_get_contents($file);
        $code = preg_replace('/(public function render\([^)]*\)[^{]*\{)/', "$1\n        throw new \\RuntimeException('motore rotto');", $code, 1);
        file_put_contents($file, $code);
    });
    $r = runUpdate($site, 'install', $zip);
    assertSame(false, $r['ok']);
    assertSame(true, $r['rolled_back']);
    assertContains('motore rotto', (string) $r['error']);
    assertSiteUntouched($site, $before);
    assertSame($modelBefore, md5_file($site . '/data/k_model.json'), 'la migrazione gia scritta deve essere annullata');
    assertSame(null, siteState($site)['data_version'] ?? null, 'lo stato torna a prima della migrazione');
});

test('se il processo muore a meta il guardiano rimette tutto a posto', function () {
    $site = updateSite();
    $before = md5_file($site . '/data/k_data.json');
    $zip = updatePackage($site, '1.0.1', function (string $kris) {
        file_put_contents($kris . '/migrations/0002_muore.php', "<?php\nreturn ['version' => 2, 'up' => function (array \$f): array { exit(1); }];\n");
    });
    runUpdate($site, 'install', $zip);
    assertSiteUntouched($site, $before);
    assertSame('rollback', lastOutcome($site));
});

test('un errore di sintassi nel pacchetto ferma tutto prima di toccare il sito', function () {
    $site = updateSite();
    $before = md5_file($site . '/data/k_data.json');
    $zip = updatePackage($site, '1.0.1', fn(string $kris) => file_put_contents($kris . '/core/rotto.php', "<?php function ( {"));
    $r = runUpdate($site, 'install', $zip);
    assertSame(false, $r['ok']);
    assertSame(false, $r['rolled_back'], 'non deve servire un ripristino: nulla e stato toccato');
    assertContains('sintassi', (string) $r['error']);
    assertSame([], glob($site . '/data/snapshots/*') ?: []);
    assertSiteUntouched($site, $before);
});

test('una manutenzione rimasta appesa si ripristina dall editor', function () {
    $site = updateSite();
    $before = md5_file($site . '/data/k_data.json');
    // Simula una richiesta uccisa dall'hosting subito dopo lo scambio,
    // senza che il guardiano potesse girare.
    $snap = $site . '/data/snapshots/20260101-000000-1.0.0-1.0.1';
    mkdir($snap . '/data', 0777, true);
    mkdir($snap . '/config', 0777, true);
    foreach (glob($site . '/data/*.json') as $file) {
        copy($file, $snap . '/data/' . basename($file));
    }
    file_put_contents($snap . '/meta.json', json_encode(['id' => basename($snap), 'outcome' => 'pending']));
    rename($site . '/kris', $snap . '/kris');
    rcopy($snap . '/kris', $site . '/kris');
    file_put_contents($site . '/kris/VERSION', "1.0.1\n");
    file_put_contents($site . '/data/k_data.json', '[]');
    file_put_contents($site . '/data/kris_maintenance.json', json_encode(['from' => '1.0.0', 'to' => '1.0.1', 'snapshot' => basename($snap), 'started_at' => time() - 3600]));

    $r = runUpdate($site, 'recover');
    assertSame(true, $r['ok'] ?? null, 'esito: ' . json_encode($r));
    assertSiteUntouched($site, $before);
});

// --- pacchetti che l'editor non deve accettare --------------------------

test('un pacchetto firmato con una chiave sconosciuta viene rifiutato', function () {
    $site = updateSite();
    $zip = updatePackage($site, '1.0.1', null, Signature::generateKeyPair()['secret']);
    $r = runUpdate($site, 'install', $zip);
    assertSame(false, $r['ok']);
    assertContains('firma', (string) $r['error']);
    assertSame('1.0.0', siteVersion($site));
});

test('una nuova major o una versione non piu recente vengono rifiutate', function () {
    $site = updateSite();
    foreach (['2.0.0' => 'sviluppatore', '1.0.0' => 'non più recente'] as $version => $expected) {
        $r = runUpdate($site, 'install', updatePackage($site, $version));
        assertSame(false, $r['ok'], "la {$version} doveva essere rifiutata");
        assertContains($expected, (string) $r['error']);
    }
    $r = runUpdate($site, 'install', updatePackage($site, '1.1.0', null, null, ['breaking' => true]));
    assertSame(false, $r['ok'], 'una release breaking doveva essere rifiutata');
    assertSame('1.0.0', siteVersion($site));
});
