<?php
declare(strict_types=1);

/**
 * tools/package.php: lo zip del sito per l'FTP contiene solo quello che
 * serve, mai la configurazione privata, lo stato o i backup.
 */

function sitePackage(string $root, array $flags): array
{
    $zipFile = sys_get_temp_dir() . '/kris-site-pkg-' . bin2hex(random_bytes(4)) . '.zip';
    register_shutdown_function(fn() => @unlink($zipFile));
    $cmd = escapeshellarg(PHP_BINARY) . (extension_loaded('zip') ? '' : ' -d extension=zip') . ' ' . escapeshellarg(KRIS_ROOT . '/tools/package.php')
        . ' ' . escapeshellarg('--root=' . $root) . ' ' . escapeshellarg('--out=' . $zipFile) . ' ' . implode(' ', array_map('escapeshellarg', $flags)) . ' 2>&1';
    exec($cmd, $output, $code);
    assertSame(0, $code, implode("\n", $output));
    $zip = new ZipArchive();
    $zip->open($zipFile);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
    return $names;
}

if (!class_exists(ZipArchive::class)) {
    skipSuite('serve l\'estensione zip');
    return;
}

test('il pacchetto del sito non contiene mai configurazione privata, stato, backup o sorgenti', function () {
    $root = sys_get_temp_dir() . '/kris-site-src-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => rrmdir($root));
    $files = [
        'index.php', 'sitemap.php', 'kris-agent.php', 'kris-admin/index.php', 'kris/VERSION', 'kris/editor/dist/index.html',
        'template/homepage.html', 'assets/css/style.css', 'assets/uploads/.htaccess', 'assets/uploads/foto.jpg',
        'config/.htaccess', 'config/auth.php', 'config/agent.php', 'config/store.php',
        'data/.htaccess', 'data/schema.json', 'data/content.json', 'data/settings.json', 'data/kris_state.json',
        'data/backups/content.json', 'data/staging/operations.json', 'data/format1/k_data.json',
        'tests/run.php', 'docs/x.md', 'tools/release.php', 'admin-src/src/main.js', 'README.md', 'AGENTS.md',
        '.git/config', 'kris/editor/dist/app.js.map', 'kris/.DS_Store', 'template/.env', 'releases.json',
    ];
    foreach ($files as $file) {
        if (!is_dir(dirname("{$root}/{$file}"))) mkdir(dirname("{$root}/{$file}"), 0777, true);
        file_put_contents("{$root}/{$file}", 'x');
    }

    $update = sitePackage($root, []);
    sort($update);
    assertSame(['assets/css/style.css', 'assets/uploads/.htaccess', 'config/.htaccess', 'data/.htaccess', 'index.php', 'kris-admin/index.php',
        'kris-agent.php', 'kris/VERSION', 'kris/editor/dist/index.html', 'sitemap.php', 'template/homepage.html'], $update);

    $first = sitePackage($root, ['--first']);
    sort($first);
    assertSame(['.htaccess', 'assets/css/style.css', 'assets/uploads/.htaccess', 'assets/uploads/foto.jpg', 'config/.htaccess',
        'data/.htaccess', 'data/content.json', 'data/schema.json', 'data/settings.json', 'index.php', 'kris-admin/index.php',
        'kris-agent.php', 'kris/VERSION', 'kris/editor/dist/index.html', 'sitemap.php', 'template/homepage.html'], $first);
});
