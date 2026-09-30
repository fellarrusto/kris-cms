<?php
declare(strict_types=1);

/**
 * Costruzione dei pacchetti di release. Usato da tools/release.php e dai
 * test: non viene spedito ai siti (sta fuori da kris/).
 */

use Kris\Update\Signature;

/** Estensioni PHP che il framework usa davvero. */
const KRIS_RELEASE_EXTENSIONS = ['dom', 'json', 'mbstring', 'session'];

/**
 * Costruisce lo zip del pacchetto a partire da una cartella kris/.
 * Aggiunge RELEASE.json, MANIFEST.json e MANIFEST.sig. Lo zip e
 * riproducibile: voci in ordine e data fissa, quindi stesso sorgente,
 * stesso sha256.
 *
 * @return string sha256 dello zip
 */
function kris_build_package(string $krisDir, string $zipFile, array $release, string $secretKey): string
{
    $build = sys_get_temp_dir() . '/kris-build-' . bin2hex(random_bytes(4));
    kris_copy_tree($krisDir, $build . '/kris');
    try {
        foreach (['RELEASE.json', 'MANIFEST.json', 'MANIFEST.sig'] as $generated) {
            @unlink($build . '/kris/' . $generated);
        }
        file_put_contents($build . '/kris/RELEASE.json', json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($build . '/kris/VERSION', $release['version'] . "\n");

        $files = [];
        foreach (\Kris\Update\Package::listFiles($build . '/kris') as $relative) {
            $files['kris/' . $relative] = hash_file('sha256', $build . '/kris/' . $relative);
        }
        ksort($files);
        $manifest = json_encode(['version' => $release['version'], 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($build . '/kris/MANIFEST.json', $manifest);
        file_put_contents($build . '/kris/MANIFEST.sig', Signature::sign($manifest, $secretKey) . "\n");

        @unlink($zipFile);
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("impossibile creare {$zipFile}");
        }
        $all = \Kris\Update\Package::listFiles($build);
        foreach ($all as $relative) {
            $zip->addFile($build . '/' . $relative, $relative);
            $zip->setMtimeName($relative, 315532800); // 1980-01-01: data fissa, zip riproducibile
            $zip->setCompressionName($relative, ZipArchive::CM_DEFLATE);
        }
        $zip->close();
        return hash_file('sha256', $zipFile);
    } finally {
        kris_remove_tree($build);
    }
}

/** Aggiunge o sostituisce una release in releases.json e lo firma. */
function kris_publish_release(string $channelFile, array $entry, string $secretKey): void
{
    $channel = is_file($channelFile) ? json_decode((string) file_get_contents($channelFile), true) : null;
    $channel = is_array($channel) ? $channel : ['format' => 1, 'releases' => []];
    $releases = array_values(array_filter($channel['releases'] ?? [], fn($r) => ($r['version'] ?? null) !== $entry['version']));
    $releases[] = $entry;
    usort($releases, fn($a, $b) => version_compare($b['version'], $a['version']));
    $channel['format'] = 1;
    $channel['releases'] = $releases;

    $json = json_encode($channel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($channelFile, $json);
    file_put_contents($channelFile . '.sig', Signature::sign($json, $secretKey) . "\n");
}

function kris_copy_tree(string $from, string $to): void
{
    if (!is_dir($to)) {
        mkdir($to, 0775, true);
    }
    foreach (scandir($from) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        is_dir("$from/$item") ? kris_copy_tree("$from/$item", "$to/$item") : copy("$from/$item", "$to/$item");
    }
}

function kris_remove_tree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            kris_remove_tree("$path/$item");
        }
    }
    @rmdir($path);
}
