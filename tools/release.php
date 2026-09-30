<?php
declare(strict_types=1);

/**
 * Prepara una release: pacchetto firmato e canale aggiornato.
 *
 * Uso:
 *   php -d extension=sodium -d extension=zip tools/release.php \
 *       --key <percorso/principale.key> --changelog "Testo per i clienti" \
 *       [--min-from 1.0.0] [--breaking]
 *
 * La versione e quella di kris/VERSION: aggiornala prima (e fai il commit).
 * Produce:
 *   dist/kris-<versione>.zip        da allegare alla release GitHub v<versione>
 *   releases.json, releases.json.sig da committare su main dopo aver pubblicato lo zip
 *
 * Il changelog lo leggono i clienti nell'editor: scrivilo per loro.
 */

require __DIR__ . '/../kris/bootstrap.php';
require __DIR__ . '/lib/release.php';

use Kris\Update\Signature;

$opts = getopt('', ['key:', 'changelog:', 'min-from::', 'breaking']);
$keyFile = $opts['key'] ?? null;
$changelog = trim((string) ($opts['changelog'] ?? ''));
if (!is_string($keyFile) || !is_file($keyFile) || $changelog === '') {
    fwrite(STDERR, "Uso: php -d extension=sodium -d extension=zip tools/release.php --key <principale.key> --changelog \"…\" [--min-from X.Y.Z] [--breaking]\n");
    exit(1);
}
if (!Signature::available() || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Servono le estensioni sodium e zip: aggiungi -d extension=sodium -d extension=zip\n");
    exit(1);
}

$repo = realpath(__DIR__ . '/..');
$version = trim((string) file_get_contents($repo . '/kris/VERSION'));
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "kris/VERSION non contiene una versione valida.\n");
    exit(1);
}
$secret = trim((string) file_get_contents($keyFile));

// La chiave deve corrispondere a una di quelle che i siti conoscono.
$probe = Signature::sign('kris', $secret);
if (!Signature::verify('kris', $probe, Signature::trustedKeys())) {
    fwrite(STDERR, "Questa chiave non corrisponde a nessuna chiave pubblica in kris/update/keys.php.\n");
    exit(1);
}

$release = [
    'version' => $version,
    'min_from' => (string) ($opts['min-from'] ?? '1.0.0'),
    'breaking' => isset($opts['breaking']),
    'requires' => ['php' => '8.1', 'ext' => KRIS_RELEASE_EXTENSIONS],
    'changelog_it' => $changelog,
    'date' => date('Y-m-d'),
];

@mkdir($repo . '/dist', 0775, true);
$zipName = "kris-{$version}.zip";
$zipFile = $repo . '/dist/' . $zipName;
$sha = kris_build_package($repo . '/kris', $zipFile, $release, $secret);

// Controllo finale con lo stesso codice che useranno i siti.
$check = sys_get_temp_dir() . '/kris-release-check-' . bin2hex(random_bytes(4));
\Kris\Update\Package::extract($zipFile, $check);
\Kris\Update\Package::verify($check . '/kris', Signature::trustedKeys());
kris_remove_tree($check);

kris_publish_release($repo . '/releases.json', $release + [
    'url' => "https://github.com/fellarrusto/kris-cms/releases/download/v{$version}/{$zipName}",
    'sha256' => $sha,
    'size' => filesize($zipFile),
], $secret);

echo "Pacchetto:  dist/{$zipName}\n";
echo "sha256:     {$sha}\n";
echo "Canale:     releases.json e releases.json.sig aggiornati\n\n";
echo "Prossimi passi:\n";
echo "  1. git tag v{$version} e push del tag\n";
echo "  2. crea la release v{$version} su GitHub e allega dist/{$zipName}\n";
echo "  3. solo dopo: commit e push di releases.json e releases.json.sig su main\n";
echo "     (prima lo zip, poi il canale: altrimenti i siti trovano una versione che non possono scaricare)\n";
