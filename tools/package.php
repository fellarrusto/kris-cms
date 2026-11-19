<?php
declare(strict_types=1);

/**
 * Prepara lo zip del sito da caricare via FTP.
 *
 *   php tools/package.php --first      primo caricamento: con i dati iniziali e gli upload
 *   php tools/package.php              aggiornamento: senza data/, config/ e upload del cliente
 *   php tools/package.php --out=sito.zip
 *
 * Entra solo quello che serve al sito, scelto per nome: mai config/ privata,
 * stato del framework, backup, staging, test, documenti, strumenti o sorgenti
 * dell'editor (che è già compilato in kris/editor/dist/).
 */

const ROOT_FILES = ['index.php', 'sitemap.php', 'kris-agent.php'];
const TREES = ['kris', 'kris-admin', 'template', 'assets'];
const ALWAYS = ['config/.htaccess', 'data/.htaccess', 'assets/uploads/.htaccess'];
const FIRST_ONLY = ['data/schema.json', 'data/content.json', 'data/settings.json'];

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
foreach ($args as $arg) {
    // Un altro sito con la stessa struttura (per i test)
    if (str_starts_with($arg, "--root=")) $root = rtrim(substr($arg, 7), "/\\");
}
$first = in_array('--first', $args, true);
$out = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
    elseif ($arg !== '--first' && !str_starts_with($arg, '--root=')) fail("Opzione sconosciuta: {$arg}. Usa --first e --out=file.zip.");
}
if (!class_exists(ZipArchive::class)) fail('Serve l\'estensione zip di PHP: php -d extension=zip tools/package.php');
if (!is_file("{$root}/kris/editor/dist/index.html") && is_dir("{$root}/admin-src")) {
    fail('Manca l\'editor compilato (kris/editor/dist/): esegui npm run build in admin-src/.');
}

$files = [];
$add = function (string $relative) use ($root, &$files): void {
    if (is_file("{$root}/{$relative}")) $files[$relative] = "{$root}/{$relative}";
};
foreach (ROOT_FILES as $file) $add($file);
foreach (TREES as $tree) {
    if (!is_dir("{$root}/{$tree}")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$tree}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (!included($relative, $first)) continue;
        $files[$relative] = $file->getPathname();
    }
}
foreach (ALWAYS as $file) $add($file);
if ($first) foreach (FIRST_ONLY as $file) $add($file);
ksort($files);

$out ??= "{$root}/dist/kris-sito-" . date('Ymd-Hi') . ($first ? '-primo' : '-aggiornamento') . '.zip';
if (!is_dir(dirname($out))) mkdir(dirname($out), 0775, true);
@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE) !== true) fail("Non riesco a creare {$out}.");
$bytes = 0;
foreach ($files as $relative => $path) {
    $zip->addFile($path, $relative);
    $bytes += (int) filesize($path);
}
if ($first && !isset($files['.htaccess'])) {
    // La regola di Kris per gli indirizzi leggibili e la 404: sul primo caricamento c'è già
    require_once __DIR__ . "/../kris/update/Stubs.php";
    $zip->addFromString('.htaccess', \Kris\Update\Stubs::HTACCESS);
}
$zip->close();

$version = trim((string) @file_get_contents("{$root}/kris/VERSION"));
echo "Pacchetto: {$out}\n";
echo 'Kris ' . ($version !== '' ? $version : '?') . ' · ' . count($files) . ' file · ' . round($bytes / 1048576, 1) . " MB non compressi\n\n";
echo $first
    ? "Primo caricamento: decomprimi nella root del sito, poi apri /kris-admin/.\n"
        . "Il primo accesso chiede il codice che Kris crea in config/setup_code.php: leggilo via FTP.\n"
        . "PHP deve poter scrivere in data/, config/ e assets/uploads/.\n"
    : "Aggiornamento: contiene kris/, il tema e gli stub, non data/, config/ né gli upload.\n"
        . "Sostituisci la cartella kris/ intera (non file per file), poi carica il resto sopra il sito.\n"
        . "Apri /kris-admin/: Kris registra la versione e propone le migrazioni se servono.\n";

/** Un file di una delle cartelle del sito entra nel pacchetto? */
function included(string $relative, bool $first): bool
{
    $parts = explode('/', $relative);
    foreach ($parts as $part) {
        // Niente file nascosti (tranne .htaccess), dipendenze o mappe dei sorgenti
        if ($part === 'node_modules' || ($part !== '.htaccess' && str_starts_with($part, '.'))) return false;
    }
    if (str_ends_with($relative, '.map')) return false;
    // I plugin si installano dall'editor su ogni sito (plugins/ non è fra le cartelle): i loro file pubblici nemmeno
    if (str_starts_with($relative, 'assets/plugins/')) return false;
    // Gli upload del cliente solo al primo caricamento
    if (str_starts_with($relative, 'assets/uploads/') && $relative !== 'assets/uploads/.htaccess') return $first;
    return true;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
