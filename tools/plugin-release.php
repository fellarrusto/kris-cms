<?php
declare(strict_types=1);

/**
 * Release di un plugin e catalogo dei plugin (docs/2.0/plugin.md, 8).
 *
 * Release, dalla radice della repository del plugin (fellarrusto/kris-plugin-<nome>,
 * con la cartella plugin/ già pronta: plugin.json, plugin.php, public/editor/ compilato):
 *   php -d extension=sodium -d extension=zip <kris-cms>/tools/plugin-release.php \
 *       --key <principale.key> --changelog "Testo per i clienti" [--publish [--dry-run]]
 *
 * Senza --publish costruisce e verifica dist/<nome>-<versione>.zip e releases.json.
 * Con --publish, come per il core: main pulito e allineato, tag vX.Y.Z, release
 * GitHub con lo zip, zip riscaricato e confrontato, releases.json(.sig) su main per ultimo.
 * Ogni passo già fatto si riconosce: se si interrompe, si rilancia lo stesso comando.
 *
 * Catalogo, dalla repository kris-cms (poi PR su main come ogni modifica):
 *   ... tools/plugin-release.php --key <principale.key> --catalog --name <nome> --title "…" --description "…"
 */

require __DIR__ . '/../kris/bootstrap.php';
require __DIR__ . '/lib/release.php';

use Kris\Plugin\Installer;
use Kris\Plugin\Manifest;
use Kris\Update\Package;
use Kris\Update\Signature;

$opts = getopt('', ['key:', 'changelog:', 'publish', 'dry-run', 'catalog', 'name:', 'title:', 'description:']);
$keyFile = $opts['key'] ?? null;
if (!is_string($keyFile) || !is_file($keyFile)) pstop('Serve --key <principale.key>.');
if (!Signature::available() || !class_exists(ZipArchive::class)) pstop('Servono le estensioni sodium e zip: aggiungi -d extension=sodium -d extension=zip');
$secret = trim((string) file_get_contents($keyFile));
if (!Signature::verify('kris', Signature::sign('kris', $secret), Signature::trustedKeys())) {
    pstop('Questa chiave non corrisponde a nessuna chiave pubblica in kris/update/keys.php.');
}

// --- catalogo ---------------------------------------------------------------
if (isset($opts['catalog'])) {
    $name = (string) ($opts['name'] ?? '');
    if (preg_match(Manifest::NAME, $name) !== 1) pstop('Serve --name con il nome del plugin.');
    $file = str_replace('\\', '/', (string) realpath(__DIR__ . '/..')) . '/plugins.json';
    $catalog = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $catalog = is_array($catalog) ? $catalog : ['format' => 1, 'plugins' => []];
    $list = array_values(array_filter($catalog['plugins'] ?? [], fn($p) => ($p['name'] ?? null) !== $name));
    $list[] = ['name' => $name, 'repository' => Installer::OWNER . '/' . Installer::repository($name),
        'title' => (string) ($opts['title'] ?? $name), 'description' => (string) ($opts['description'] ?? '')];
    usort($list, fn($a, $b) => strcmp($a['name'], $b['name']));
    $json = json_encode(['format' => 1, 'plugins' => $list], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($file, $json);
    file_put_contents($file . '.sig', Signature::sign($json, $secret) . "\n");
    echo "plugins.json e plugins.json.sig aggiornati con «{$name}»: committali su main con una PR.\n";
    exit(0);
}

// --- release ----------------------------------------------------------------
$changelog = trim((string) ($opts['changelog'] ?? ''));
if ($changelog === '') pstop('Serve --changelog "…" (lo leggono i clienti nell\'editor).');
$publish = isset($opts['publish']);
$dryRun = isset($opts['dry-run']);
$repo = str_replace('\\', '/', (string) getcwd());
try {
    $manifest = Manifest::parse((string) @file_get_contents($repo . '/plugin/plugin.json'));
} catch (\Kris\Plugin\PluginException $e) {
    pstop('plugin/plugin.json: ' . $e->getMessage());
}
$name = $manifest['name'];
$version = $manifest['version'];
$repository = Installer::repository($name);
$tag = 'v' . $version;
$zipName = "{$name}-{$version}.zip";
$zipFile = $repo . '/dist/' . $zipName;
$url = 'https://github.com/' . Installer::OWNER . "/{$repository}/releases/download/{$tag}/{$zipName}";
$gh = ' --repo ' . escapeshellarg(Installer::OWNER . '/' . $repository);

if ($publish) {
    pstep('Controllo della repository');
    $problems = [];
    if (trim(psh('git rev-parse --abbrev-ref HEAD')) !== 'main') $problems[] = 'non sei su main';
    $dirty = array_filter(explode("\n", trim(psh('git status --porcelain'))), fn($l) => $l !== '' && !preg_match('#^\?\? (releases\.json(\.sig)?|dist/)$#', $l));
    if ($dirty) $problems[] = "modifiche non committate:\n    " . implode("\n    ", $dirty);
    psh('git fetch -q --tags origin');
    if (trim(psh('git rev-parse HEAD')) !== trim(psh('git rev-parse origin/main'))) $problems[] = 'main non è allineato a origin/main';
    if (!str_contains(psh('git remote get-url origin'), $repository)) $problems[] = "il remoto origin non è {$repository}";
    if ($problems) {
        foreach ($problems as $p) echo "  ✗ {$p}\n";
        if (!$dryRun) pstop('Correggi e rilancia.');
    } else {
        echo "  ✓ main pulito e allineato\n";
    }
}

pstep("Pacchetto {$zipName}");
if (!is_dir($repo . '/dist')) mkdir($repo . '/dist', 0775, true);
$published = $publish && psh('gh release view ' . escapeshellarg($tag) . $gh . ' --json assets -q ".assets[].name"', false) !== '';
if ($published && in_array($zipName, explode("\n", psh('gh release view ' . escapeshellarg($tag) . $gh . ' --json assets -q ".assets[].name"', false)), true)) {
    // Ripresa: lo zip pubblicato è quello buono, non si ricostruisce
    @unlink($zipFile);
    psh('gh release download ' . escapeshellarg($tag) . $gh . ' -p ' . escapeshellarg($zipName) . ' -D ' . escapeshellarg($repo . '/dist'));
    $sha = hash_file('sha256', $zipFile);
} else {
    $sha = kris_build_plugin_package($repo . '/plugin', $zipFile, $secret)['sha256'];
}
// Verifica con il codice dei siti
$check = sys_get_temp_dir() . '/kris-plugin-check-' . bin2hex(random_bytes(4));
Package::extract($zipFile, $check, 'plugin');
$signed = Package::verifyFiles($check . '/plugin', Signature::trustedKeys());
kris_remove_tree($check);
if ($signed['version'] !== $version) pstop('Lo zip ha un\'altra versione.');
echo "  ✓ dist/{$zipName}  sha256 {$sha}\n";

$channel = $repo . '/dist/releases.json';
$base = $publish ? psh('git show origin/main:releases.json', false) : (string) @file_get_contents($repo . '/releases.json');
file_put_contents($channel, $base !== '' ? $base : json_encode(['format' => 1, 'releases' => []]));
kris_publish_release($channel, ['version' => $version, 'zip' => $url, 'sha256' => $sha, 'size' => filesize($zipFile),
    'requires' => ['kris' => $manifest['requires']['kris'], 'php' => $manifest['requires']['php']], 'changelog_it' => $changelog, 'date' => date('Y-m-d')], $secret);
echo "  ✓ releases.json firmato\n";

if (!$publish) {
    copy($channel, $repo . '/releases.json');
    copy($channel . '.sig', $repo . '/releases.json.sig');
    echo "\nreleases.json aggiornato in locale. Per pubblicare rilancia con --publish.\n";
    exit(0);
}
pstep('Riepilogo');
echo "  {$manifest['title']} {$version}\n  {$changelog}\n  {$url}\n";
if ($dryRun) {
    echo "\n--dry-run: niente è stato pubblicato.\n";
    exit(0);
}
echo "\nPubblico tag, release e canale? Scrivi 'si' per confermare: ";
if (strtolower(trim((string) fgets(STDIN))) !== 'si') pstop('Annullato: niente è stato pubblicato.');

pstep("Tag {$tag}");
if (trim(psh('git rev-parse -q --verify ' . escapeshellarg("refs/tags/{$tag}"), false)) === '') psh('git tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg("{$manifest['title']} {$version}"));
if (trim(psh('git ls-remote --tags origin ' . escapeshellarg("refs/tags/{$tag}"), false)) === '') psh('git push -q origin ' . escapeshellarg($tag));
echo "  ✓ tag pushato\n";

pstep('Release GitHub');
if (!$published) psh('gh release create ' . escapeshellarg($tag) . ' ' . escapeshellarg($zipFile) . $gh . ' --title ' . escapeshellarg("{$manifest['title']} {$version}") . ' --notes ' . escapeshellarg($changelog));
elseif (!in_array($zipName, explode("\n", psh('gh release view ' . escapeshellarg($tag) . $gh . ' --json assets -q ".assets[].name"', false)), true)) psh('gh release upload ' . escapeshellarg($tag) . ' ' . escapeshellarg($zipFile) . $gh);
$download = sys_get_temp_dir() . '/kris-plugin-dl-' . bin2hex(random_bytes(4));
mkdir($download);
psh('gh release download ' . escapeshellarg($tag) . $gh . ' -p ' . escapeshellarg($zipName) . ' -D ' . escapeshellarg($download));
$remote = hash_file('sha256', "{$download}/{$zipName}");
kris_remove_tree($download);
if ($remote !== $sha) pstop("Lo zip della release ha sha256 {$remote}, diverso da quello firmato: il canale NON è stato pubblicato.");
echo "  ✓ zip pubblicato e verificato\n";

pstep('Canale su main');
copy($channel, $repo . '/releases.json');
copy($channel . '.sig', $repo . '/releases.json.sig');
psh('git add releases.json releases.json.sig');
if (trim(psh('git diff --cached --name-only')) !== '') {
    psh('git commit -q -m ' . escapeshellarg("Canale: {$manifest['title']} {$version}"));
    psh('git push -q origin main');
}
echo "  ✓ pubblicato\n\n{$manifest['title']} {$version} è disponibile per i siti.\n";

function pstep(string $title): void
{
    echo "\n{$title}\n";
}

function pstop(string $message): never
{
    fwrite(STDERR, "\n✗ {$message}\n");
    exit(1);
}

function psh(string $cmd, bool $strict = true): string
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        if ($strict) pstop("Comando fallito: {$cmd}\n" . trim($err . "\n" . $out));
        return '';
    }
    return rtrim((string) $out, "\r\n");
}
