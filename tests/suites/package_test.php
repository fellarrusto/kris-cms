<?php
declare(strict_types=1);

/**
 * Pacchetti di aggiornamento: estrazione blindata e verifica della firma.
 */

use Kris\Update\Package;
use Kris\Update\Signature;
use Kris\Update\UpdateException;

if (!Signature::available() || !class_exists(ZipArchive::class)) {
    skipSuite('servono le estensioni PHP sodium e zip (php -d extension=sodium -d extension=zip tests/run.php)');
    return;
}

require_once KRIS_ROOT . '/tools/lib/release.php';

function tempPath(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . '-' . getmypid() . '-' . uniqid();
    register_shutdown_function(fn() => rrmdir($path));
    return $path;
}

/** Zip con le voci date, per provare le difese dell'estrazione. */
function rawZip(array $entries): string
{
    $file = tempPath('kris-raw') . '.zip';
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    register_shutdown_function(fn() => @unlink($file));
    return $file;
}

function assertExtractRefused(array $entries): void
{
    try {
        Package::extract(rawZip($entries), tempPath('kris-x'));
    } catch (UpdateException) {
        return;
    }
    fail('estrazione accettata: ' . implode(', ', array_keys($entries)));
}

/** Pacchetto valido, estratto; restituisce [cartella kris, chiavi]. */
function builtPackage(string $version = '1.0.1'): array
{
    $keys = Signature::generateKeyPair();
    $zip = tempPath('kris-pkg') . '.zip';
    kris_build_package(KRIS_ROOT . '/kris', $zip, [
        'version' => $version, 'min_from' => '1.0.0', 'breaking' => false,
        'requires' => ['php' => '8.1', 'ext' => []], 'changelog_it' => 'prova',
    ], $keys['secret']);
    register_shutdown_function(fn() => @unlink($zip));
    $dir = tempPath('kris-ext');
    Package::extract($zip, $dir);
    return [$dir . '/kris', $keys];
}

function assertVerifyRefused(string $kris, array $publicKeys): void
{
    try {
        Package::verify($kris, $publicKeys);
    } catch (UpdateException) {
        return;
    }
    fail('pacchetto accettato ma doveva essere rifiutato');
}

test('la firma verifica solo con la chiave giusta e solo lo stesso contenuto', function () {
    $a = Signature::generateKeyPair();
    $b = Signature::generateKeyPair();
    $sig = Signature::sign('ciao', $a['secret']);
    assertSame(true, Signature::verify('ciao', $sig, [$b['public'], $a['public']]));
    assertSame(false, Signature::verify('ciao', $sig, [$b['public']]));
    assertSame(false, Signature::verify('ciaO', $sig, [$a['public']]));
    assertSame(false, Signature::verify('ciao', 'non-base64!', [$a['public']]));
    assertSame(false, Signature::verify('ciao', $sig, ['chiave-rotta']));
});

test('le chiavi pubbliche del framework sono due chiavi Ed25519 valide', function () {
    $keys = Signature::trustedKeys();
    assertSame(2, count($keys));
    foreach ($keys as $key) {
        assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen((string) base64_decode($key, true)));
    }
});

test('l estrazione rifiuta percorsi fuori da kris/ o con ..', function () {
    assertExtractRefused(['../evil.php' => 'x']);
    assertExtractRefused(['kris/../../evil.php' => 'x']);
    assertExtractRefused(['/etc/evil.php' => 'x']);
    assertExtractRefused(['altro/file.php' => 'x']);
    assertExtractRefused(['kris\\evil.php' => 'x']);
    assertExtractRefused(['kris/C:evil.php' => 'x']);
    assertExtractRefused(['kris//doppio.php' => 'x']);
});

test('un pacchetto integro e firmato passa la verifica', function () {
    [$kris, $keys] = builtPackage('1.0.1');
    $release = Package::verify($kris, [$keys['public']]);
    assertSame('1.0.1', $release['version']);
    assertSame('1.0.1', trim(file_get_contents($kris . '/VERSION')));
});

test('un pacchetto firmato con un altra chiave viene rifiutato', function () {
    [$kris] = builtPackage();
    assertVerifyRefused($kris, [Signature::generateKeyPair()['public']]);
});

test('un file modificato dopo la firma viene rifiutato', function () {
    [$kris, $keys] = builtPackage();
    file_put_contents($kris . '/public.php', "<?php echo 'manomesso';", FILE_APPEND);
    assertVerifyRefused($kris, [$keys['public']]);
});

test('un file aggiunto o tolto dopo la firma viene rifiutato', function () {
    [$kris, $keys] = builtPackage();
    file_put_contents($kris . '/extra.php', '<?php');
    assertVerifyRefused($kris, [$keys['public']]);

    [$kris2, $keys2] = builtPackage();
    unlink($kris2 . '/404.php');
    assertVerifyRefused($kris2, [$keys2['public']]);
});

test('lo stesso sorgente produce lo stesso zip', function () {
    $keys = Signature::generateKeyPair();
    $release = ['version' => '1.0.1', 'requires' => ['php' => '8.1', 'ext' => []]];
    $a = tempPath('kris-a') . '.zip';
    $b = tempPath('kris-b') . '.zip';
    $shaA = kris_build_package(KRIS_ROOT . '/kris', $a, $release, $keys['secret']);
    $shaB = kris_build_package(KRIS_ROOT . '/kris', $b, $release, $keys['secret']);
    @unlink($a);
    @unlink($b);
    assertSame($shaA, $shaB);
});
