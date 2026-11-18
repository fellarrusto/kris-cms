<?php
declare(strict_types=1);

/**
 * Stadio 1 dell'aggiornamento senza rete ne estensioni: quale versione si
 * installa dall'editor e quali indirizzi si accettano.
 */

use Kris\Update\Http;
use Kris\Update\Updater;
use Kris\Update\UpdateException;

function rel(string $version, array $extra = []): array
{
    return $extra + ['version' => $version, 'min_from' => '1.0.0', 'breaking' => false];
}

$noRequirements = fn(array $r): ?string => null;

test('sceglie la versione piu alta con la stessa major', function () use ($noRequirements) {
    $r = Updater::selectTarget('1.0.0', [rel('1.0.1'), rel('1.2.0'), rel('1.1.5')], $noRequirements);
    assertSame('1.2.0', $r['target']['version']);
    assertSame(null, $r['blocked']);
});

test('confronta le versioni come numeri, non come stringhe', function () use ($noRequirements) {
    $r = Updater::selectTarget('1.0.9', [rel('1.0.10')], $noRequirements);
    assertSame('1.0.10', $r['target']['version']);
});

test('ignora le versioni vecchie o uguali a quella installata', function () use ($noRequirements) {
    $r = Updater::selectTarget('1.3.0', [rel('1.2.0'), rel('1.3.0')], $noRequirements);
    assertSame(null, $r['target']);
    assertSame([], $r['newer']);
});

test('una major nuova non si installa dall editor ma viene segnalata', function () use ($noRequirements) {
    $r = Updater::selectTarget('1.0.0', [rel('1.1.0'), rel('2.0.0')], $noRequirements);
    assertSame('1.1.0', $r['target']['version']);
    assertSame('2.0.0', $r['blocked']['version']);
});

test('una release marcata breaking resta allo sviluppatore anche nella stessa major', function () use ($noRequirements) {
    $r = Updater::selectTarget('1.0.0', [rel('1.1.0', ['breaking' => true]), rel('1.0.5')], $noRequirements);
    assertSame('1.0.5', $r['target']['version']);
    assertSame('1.1.0', $r['blocked']['version']);
});

test('rispetta la versione minima di partenza e ripiega su una intermedia', function () use ($noRequirements) {
    $r = Updater::selectTarget('1.0.0', [rel('1.4.0', ['min_from' => '1.2.0']), rel('1.2.0')], $noRequirements);
    assertSame('1.2.0', $r['target']['version']);
});

test('se l hosting non soddisfa i requisiti lo dice invece di proporre la release', function () {
    $r = Updater::selectTarget('1.0.0', [rel('1.1.0')], fn(array $x): ?string => 'serve PHP 9');
    assertSame(null, $r['target']);
    assertSame('serve PHP 9', $r['unmet']);
});

test('ignora le voci del canale con versioni malformate', function () use ($noRequirements) {
    $r = Updater::selectTarget('1.0.0', [rel('1.1'), rel('v1.2.0'), ['nope'], rel('1.0.1')], $noRequirements);
    assertSame('1.0.1', $r['target']['version']);
});

function assertRejected(string $url): void
{
    try {
        (new Http())->assertAllowed($url);
    } catch (UpdateException) {
        return;
    }
    fail("l'indirizzo {$url} doveva essere rifiutato");
}

test('accetta https sui soli host di GitHub previsti', function () {
    (new Http())->assertAllowed('https://raw.githubusercontent.com/fellarrusto/kris-cms/main/releases.json');
    (new Http())->assertAllowed('https://github.com/fellarrusto/kris-cms/releases/download/v1.0.1/kris-1.0.1.zip');
    assertTrue(true);
});

test('rifiuta indirizzi non https, altri host, porte e credenziali', function () {
    assertRejected('http://raw.githubusercontent.com/x/releases.json');
    assertRejected('https://example.com/releases.json');
    assertRejected('https://raw.githubusercontent.com.evil.com/releases.json');
    assertRejected('https://127.0.0.1/releases.json');
    assertRejected('https://raw.githubusercontent.com:8443/releases.json');
    assertRejected('https://user:pass@github.com/x');
    assertRejected('file:///etc/passwd');
    assertRejected('releases.json');
});

test('senza config/update.php gli aggiornamenti sono attivi sul canale ufficiale', function () {
    $config = Updater::loadConfig(sys_get_temp_dir() . '/kris-no-config-' . getmypid());
    assertSame(true, $config['enabled']);
    assertSame(Updater::DEFAULT_CHANNEL, $config['channel']);
});

test('la major successiva si installa dall editor solo se lo dichiara e dalla versione minima indicata', function () use ($noRequirements) {
    $two = rel('2.0.0', ['editor_upgrade' => true, 'min_from' => '1.10.0']);
    assertSame('2.0.0', Updater::selectTarget('1.10.0', [$two], $noRequirements)['target']['version']);
    $old = Updater::selectTarget('1.9.1', [$two, rel('1.10.0')], $noRequirements);
    assertSame('1.10.0', $old['target']['version'], 'prima il passaggio intermedio');
    assertSame(null, Updater::selectTarget('1.10.0', [rel('2.0.0')], $noRequirements)['target'], 'senza editor_upgrade resta allo sviluppatore');
    assertSame(null, Updater::selectTarget('1.10.0', [rel('3.0.0', ['editor_upgrade' => true])], $noRequirements)['target'], 'mai due major in un salto');
    assertSame(null, Updater::selectTarget('1.10.0', [rel('2.0.0', ['editor_upgrade' => true, 'breaking' => true])], $noRequirements)['target'], 'breaking vince');
});

test('prima della major nuova si segnalano .htaccess senza la regola di Kris e stub dell editor modificati', function () {
    $root = sys_get_temp_dir() . '/kris-upg-' . bin2hex(random_bytes(3));
    mkdir("{$root}/editor", 0777, true);
    register_shutdown_function(fn() => rrmdir($root));
    file_put_contents("{$root}/editor/index.php", "<?php\n// personalizzato\n");
    $warnings = Updater::upgradeWarnings($root, '1.10.0', ['version' => '2.0.0']);
    assertSame(2, count($warnings), implode(' | ', $warnings));
    file_put_contents("{$root}/.htaccess", \Kris\Update\Stubs::HTACCESS);
    file_put_contents("{$root}/editor/index.php", "<?php\nrequire __DIR__ . '/../kris/editor/index.php';\n");
    assertSame([], Updater::upgradeWarnings($root, '1.10.0', ['version' => '2.0.0']));
    assertSame([], Updater::upgradeWarnings($root, '1.10.0', ['version' => '1.10.1']), 'stessa major: niente');
});
