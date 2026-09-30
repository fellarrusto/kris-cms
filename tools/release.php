<?php
declare(strict_types=1);

/**
 * Prepara e, con --publish, pubblica una release di Kris.
 *
 * Solo pacchetto e canale in locale:
 *   php -d extension=sodium -d extension=zip tools/release.php \
 *       --key <percorso/principale.key> --changelog "Testo per i clienti" \
 *       [--min-from 1.0.0] [--breaking]
 *
 * Tutto il giro, dal PC dello sviluppatore:
 *   ... tools/release.php --key … --changelog "…" --publish [--dry-run]
 *
 * Con --publish, in quest'ordine, fermandosi al primo problema:
 *   1. main pulito e allineato al remoto, versione piu alta dell'ultimo tag
 *   2. suite dei test verde, senza suite saltate
 *   3. pacchetto e canale costruiti e verificati con il codice dei siti
 *   4. conferma esplicita (--dry-run si ferma qui)
 *   5. tag vX.Y.Z
 *   6. release GitHub con lo zip allegato
 *   7. zip riscaricato dalla release e confrontato
 *   8. releases.json e releases.json.sig committati e pushati su main
 *
 * Ogni passo gia fatto viene riconosciuto e saltato: se qualcosa si
 * interrompe, si rilancia lo stesso comando. Prima lo zip, poi il canale:
 * altrimenti i siti troverebbero una versione che non possono scaricare.
 *
 * Il changelog lo leggono i clienti nell'editor: scrivilo per loro.
 * Servono git e gh (GitHub CLI) autenticato.
 */

require __DIR__ . '/../kris/bootstrap.php';
require __DIR__ . '/lib/release.php';

use Kris\Update\Package;
use Kris\Update\Signature;

$opts = getopt('', ['key:', 'changelog:', 'min-from::', 'breaking', 'publish', 'dry-run']);
$keyFile = $opts['key'] ?? null;
$changelog = trim((string) ($opts['changelog'] ?? ''));
$publish = isset($opts['publish']);
$dryRun = isset($opts['dry-run']);

if (!is_string($keyFile) || !is_file($keyFile) || $changelog === '') {
    fwrite(STDERR, "Uso: php -d extension=sodium -d extension=zip tools/release.php --key <principale.key> --changelog \"…\" [--min-from X.Y.Z] [--breaking] [--publish [--dry-run]]\n");
    exit(1);
}
if (!Signature::available() || !class_exists(ZipArchive::class)) {
    stop('Servono le estensioni sodium e zip: aggiungi -d extension=sodium -d extension=zip');
}

$repo = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
chdir($repo);
$version = trim((string) file_get_contents($repo . '/kris/VERSION'));
$tag = 'v' . $version;
$zipName = "kris-{$version}.zip";
$zipFile = $repo . '/dist/' . $zipName;
$url = "https://github.com/fellarrusto/kris-cms/releases/download/{$tag}/{$zipName}";

if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    stop('kris/VERSION non contiene una versione valida.');
}
$secret = trim((string) file_get_contents($keyFile));
if (!Signature::verify('kris', Signature::sign('kris', $secret), Signature::trustedKeys())) {
    stop('Questa chiave non corrisponde a nessuna chiave pubblica in kris/update/keys.php.');
}

// --- 1. stato della repository ---------------------------------------------

if ($publish) {
    step('Controllo della repository');
    $problems = [];
    if (trim(sh('git rev-parse --abbrev-ref HEAD')) !== 'main') {
        $problems[] = 'non sei su main';
    }
    $dirty = array_filter(explode("\n", trim(sh('git status --porcelain'))), fn($l) => $l !== ''
        && !preg_match('#^\?\? (releases\.json(\.sig)?|dist/)$#', $l));
    if ($dirty) {
        $problems[] = "ci sono modifiche non committate:\n    " . implode("\n    ", $dirty);
    }
    sh('git fetch -q --tags origin');
    if (trim(sh('git rev-parse HEAD')) !== trim(sh('git rev-parse origin/main'))) {
        $problems[] = 'main locale non e allineato a origin/main (git pull)';
    }
    sh('gh auth status');

    // ^{commit} dentro le virgolette: fuori, cmd.exe tratta ^ come escape.
    $tagCommit = trim(sh('git rev-parse -q --verify ' . escapeshellarg("refs/tags/{$tag}^{commit}"), false));
    if ($tagCommit !== '') {
        // Ripresa: il tag c'e gia, il codice rilasciato deve essere quello.
        if (trim(sh('git diff --name-only ' . escapeshellarg($tag) . ' HEAD -- kris')) !== '') {
            $problems[] = "il tag {$tag} esiste ma kris/ e cambiata da allora: aumenta la versione in kris/VERSION";
        }
    } else {
        $tags = array_filter(explode("\n", trim(sh('git tag -l "v*"'))), fn($t) => preg_match('/^v\d+\.\d+\.\d+$/', $t));
        foreach ($tags as $t) {
            if (version_compare(substr($t, 1), $version, '>=')) {
                $problems[] = "esiste gia il tag {$t}: kris/VERSION ({$version}) deve essere piu alta";
            }
        }
    }
    if ($problems) {
        foreach ($problems as $p) {
            echo "  ✗ {$p}\n";
        }
        if (!$dryRun) {
            stop('Correggi e rilancia.');
        }
        echo "  (--dry-run: continuo lo stesso per mostrare il resto)\n";
    } else {
        echo "  ✓ main pulito e allineato" . ($tagCommit !== '' ? ", tag {$tag} gia presente: riprendo da li" : '') . "\n";
    }

    // --- 2. test -------------------------------------------------------------
    step('Test');
    $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=sodium -d extension=zip tests/run.php 2>&1';
    exec($cmd, $out, $code);
    $summary = (string) current(array_filter($out, fn($l) => str_contains($l, ' ok, ')));
    if ($code !== 0 || str_contains(implode("\n", $out), 'SALTATA')) {
        echo implode("\n", array_slice($out, -8)) . "\n";
        stop('La suite non e verde (o ha suite saltate): niente release.');
    }
    echo "  ✓ {$summary}\n";
}

// --- 3. pacchetto e canale --------------------------------------------------

step('Pacchetto e canale');
$release = [
    'version' => $version,
    'min_from' => (string) ($opts['min-from'] ?? '1.0.0'),
    'breaking' => isset($opts['breaking']),
    'requires' => ['php' => '8.0', 'ext' => KRIS_RELEASE_EXTENSIONS],
    'changelog_it' => $changelog,
    'date' => date('Y-m-d'),
];
@mkdir($repo . '/dist', 0775, true);

// Ripresa: se la release ha gia lo zip, il canale deve puntare a quello,
// non a uno ricostruito ora (changelog o data diversi cambierebbero l'hash).
$published = false;
if ($publish && sh('gh release view ' . escapeshellarg($tag) . ' --json assets -q ".assets[].name"', false) !== '') {
    $names = explode("\n", sh('gh release view ' . escapeshellarg($tag) . ' --json assets -q ".assets[].name"', false));
    if (in_array($zipName, $names, true)) {
        @unlink($zipFile);
        sh('gh release download ' . escapeshellarg($tag) . ' -p ' . escapeshellarg($zipName) . ' -D ' . escapeshellarg($repo . '/dist'));
        $published = true;
    }
}
if (!$published) {
    kris_build_package($repo . '/kris', $zipFile, $release, $secret);
}
$sha = hash_file('sha256', $zipFile);

// Controllo con lo stesso codice che useranno i siti.
$check = sys_get_temp_dir() . '/kris-release-check-' . bin2hex(random_bytes(4));
Package::extract($zipFile, $check);
$packaged = Package::verify($check . '/kris', Signature::trustedKeys());
kris_remove_tree($check);
if ($published) {
    // Le voci del canale vengono dal pacchetto pubblicato, non dagli argomenti.
    $release = array_intersect_key($packaged, $release) + $release;
    echo "  ✓ la release ha gia {$zipName}: uso quello (verificato)\n";
}

// Il canale parte da quello pubblicato su main, non da copie locali.
$channelFile = $repo . '/dist/releases.json';
$base = $publish ? sh('git show origin/main:releases.json', false) : (string) @file_get_contents($repo . '/releases.json');
file_put_contents($channelFile, $base !== '' ? $base : json_encode(['format' => 1, 'releases' => []]));
kris_publish_release($channelFile, $release + ['url' => $url, 'sha256' => $sha, 'size' => filesize($zipFile)], $secret);
if (!Signature::verify((string) file_get_contents($channelFile), (string) file_get_contents($channelFile . '.sig'), Signature::trustedKeys())) {
    stop('La firma del canale appena generato non verifica.');
}
echo "  ✓ dist/{$zipName}  sha256 {$sha}\n  ✓ canale firmato e verificato\n";

if (!$publish) {
    copy($channelFile, $repo . '/releases.json');
    copy($channelFile . '.sig', $repo . '/releases.json.sig');
    echo "\nreleases.json e releases.json.sig aggiornati. Per pubblicare rilancia con --publish.\n";
    exit(0);
}

// --- 4. conferma --------------------------------------------------------------

step('Riepilogo');
echo "  Versione:   {$version}" . ($release['breaking'] ? '  (BREAKING: solo sviluppatore)' : '') . "\n";
echo "  Da:         {$release['min_from']}\n";
echo "  Changelog:  {$release['changelog_it']}\n";
echo "  URL:        {$url}\n";
if ($dryRun) {
    echo "\n--dry-run: niente è stato pubblicato.\n";
    exit(0);
}
echo "\nPubblico tag, release e canale? Scrivi 'si' per confermare: ";
if (strtolower(trim((string) fgets(STDIN))) !== 'si') {
    stop('Annullato: niente è stato pubblicato.');
}

// --- 5. tag ---------------------------------------------------------------

step("Tag {$tag}");
if (trim(sh('git rev-parse -q --verify ' . escapeshellarg("refs/tags/{$tag}"), false)) === '') {
    sh('git tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg("Kris {$version}"));
    echo "  ✓ creato\n";
} else {
    echo "  ✓ gia presente\n";
}
if (trim(sh('git ls-remote --tags origin ' . escapeshellarg("refs/tags/{$tag}"), false)) === '') {
    sh('git push -q origin ' . escapeshellarg($tag));
    echo "  ✓ pushato\n";
}

// --- 6. release GitHub ------------------------------------------------------

step('Release GitHub');
$assets = sh('gh release view ' . escapeshellarg($tag) . ' --json assets -q ".assets[].name"', false);
if (sh('gh release view ' . escapeshellarg($tag) . ' --json tagName -q .tagName', false) === '') {
    sh('gh release create ' . escapeshellarg($tag) . ' ' . escapeshellarg($zipFile)
        . ' --title ' . escapeshellarg("Kris {$version}") . ' --notes ' . escapeshellarg($changelog));
    echo "  ✓ creata con {$zipName}\n";
} elseif (!in_array($zipName, explode("\n", trim($assets)), true)) {
    sh('gh release upload ' . escapeshellarg($tag) . ' ' . escapeshellarg($zipFile));
    echo "  ✓ esisteva, zip allegato\n";
} else {
    echo "  ✓ esiste gia con {$zipName}\n";
}

// --- 7. lo zip pubblicato e quello firmato? -------------------------------------

step('Verifica dello zip pubblicato');
$download = sys_get_temp_dir() . '/kris-dl-' . bin2hex(random_bytes(4));
mkdir($download);
sh('gh release download ' . escapeshellarg($tag) . ' -p ' . escapeshellarg($zipName) . ' -D ' . escapeshellarg($download));
$remoteSha = hash_file('sha256', $download . '/' . $zipName);
kris_remove_tree($download);
if ($remoteSha !== $sha) {
    // Uno zip vecchio gia allegato: il canale non deve puntarci con un altro hash.
    stop("Lo zip allegato alla release ha sha256 {$remoteSha}, diverso da quello appena firmato. Il canale NON è stato pubblicato: sostituisci lo zip (gh release upload {$tag} dist/{$zipName} --clobber) e rilancia.");
}
echo "  ✓ sha256 identico\n";

// --- 8. canale su main ------------------------------------------------------

step('Canale su main');
copy($channelFile, $repo . '/releases.json');
copy($channelFile . '.sig', $repo . '/releases.json.sig');
sh('git add releases.json releases.json.sig');
if (trim(sh('git diff --cached --name-only')) === '') {
    echo "  ✓ gia pubblicato\n";
} else {
    sh('git commit -q -m ' . escapeshellarg("Canale: Kris {$version}"));
    sh('git push -q origin main');
    echo "  ✓ committato e pushato\n";
}

echo "\nKris {$version} è pubblicata. I siti la vedono con \"Verifica aggiornamenti\" (raw.githubusercontent.com può metterci qualche minuto).\n";

// --- utilita ------------------------------------------------------------------

function step(string $title): void
{
    echo "\n{$title}\n";
}

function stop(string $message): void // termina sempre con exit
{
    fwrite(STDERR, "\n✗ {$message}\n");
    exit(1);
}

/**
 * Esegue un comando e restituisce lo stdout, tenuto separato dallo stderr:
 * un avviso di git non deve finire, per esempio, dentro releases.json.
 * Se $strict e il comando fallisce, si ferma mostrando lo stderr.
 */
function sh(string $cmd, bool $strict = true): string
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        if ($strict) {
            stop("Comando fallito: {$cmd}\n" . trim($err . "\n" . $out));
        }
        return '';
    }
    return rtrim((string) $out, "\r\n");
}
