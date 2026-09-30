<?php
declare(strict_types=1);

/**
 * Runner delle migrazioni, su un sito temporaneo con migrazioni finte.
 * Le migrazioni vere di kris/migrations hanno i loro test in fondo.
 */

use Kris\Update\Migrator;
use Kris\Update\UpdateException;

/** Sito temporaneo con data/ e una cartella di migrazioni con i file dati. */
function migrationSite(array $migrations, ?array $kData = null): array
{
    $root = sys_get_temp_dir() . '/kris-mig-' . getmypid() . '-' . uniqid();
    mkdir($root . '/data', 0777, true);
    mkdir($root . '/migrations', 0777, true);
    file_put_contents($root . '/data/k_data.json', json_encode($kData ?? [['id' => 0, 'name' => 'homepage', 'data' => []]]));
    file_put_contents($root . '/data/k_model.json', '{"homepage": []}');
    foreach ($migrations as $name => $code) {
        file_put_contents("{$root}/migrations/{$name}.php", "<?php\nreturn {$code};\n");
    }
    register_shutdown_function(fn() => rrmdir($root));
    return [$root, new Migrator($root, $root . '/migrations')];
}

test('su un sito senza stato tutte le migrazioni sono in sospeso', function () {
    [, $m] = migrationSite([
        '0001_a' => "['version' => 1, 'auto' => true, 'up' => fn(\$f) => \$f]",
        '0002_b' => "['version' => 2, 'up' => fn(\$f) => \$f]",
    ]);
    assertSame(['0001_a', '0002_b'], $m->pending([]));
    assertSame(2, $m->latestDataVersion());
    assertSame(false, $m->pendingAreAutomatic([]));
    assertSame(true, $m->pendingAreAutomatic(['migrations' => ['0002_b']]));
});

test('applica le migrazioni in ordine e registra versione ed elenco', function () {
    [$root, $m] = migrationSite([
        '0001_a' => "['version' => 1, 'up' => function (\$f) { \$f['k_model']['homepage'][] = ['name' => 'a', 'type' => 'text']; return \$f; }]",
        '0002_b' => "['version' => 2, 'up' => function (\$f) { \$f['k_model']['homepage'][0]['name'] = 'b'; return \$f; }]",
    ]);
    $state = $m->run(['migrations' => []]);
    assertSame(['0001_a', '0002_b'], $state['migrations']);
    assertSame(2, $state['data_version']);
    $model = json_decode(file_get_contents($root . '/data/k_model.json'), true);
    assertSame('b', $model['homepage'][0]['name']);
});

test('non riapplica le migrazioni gia registrate', function () {
    [$root, $m] = migrationSite([
        '0001_a' => "['version' => 1, 'up' => function (\$f) { \$f['k_model']['x'] = []; return \$f; }]",
    ]);
    $state = $m->run(['migrations' => ['0001_a'], 'data_version' => 1]);
    assertSame(['0001_a'], $state['migrations']);
    assertSame('{"homepage": []}', file_get_contents($root . '/data/k_model.json'));
});

test('una migrazione che non cambia i dati non riscrive i file', function () {
    [$root, $m] = migrationSite(['0001_a' => "['version' => 1, 'up' => fn(\$f) => \$f]"]);
    $before = filemtime($root . '/data/k_data.json');
    touch($root . '/data/k_data.json', $before - 100);
    $m->run([]);
    assertSame($before - 100, filemtime($root . '/data/k_data.json'));
});

test('se una migrazione fallisce nessun file viene scritto', function () {
    [$root, $m] = migrationSite([
        '0001_a' => "['version' => 1, 'up' => function (\$f) { \$f['k_model']['nuovo'] = []; return \$f; }]",
        '0002_b' => "['version' => 2, 'up' => function (\$f) { throw new RuntimeException('rotta'); }]",
    ]);
    try {
        $m->run([]);
        fail('mi aspettavo un errore');
    } catch (RuntimeException $e) {
        assertSame('rotta', $e->getMessage());
    }
    assertSame('{"homepage": []}', file_get_contents($root . '/data/k_model.json'));
});

test('una migrazione che restituisce dati malformati viene fermata', function () {
    [, $m] = migrationSite(['0001_a' => "['version' => 1, 'up' => fn(\$f) => ['k_data' => 'rotto']]"]);
    try {
        $m->run([]);
    } catch (UpdateException) {
        assertTrue(true);
        return;
    }
    fail('mi aspettavo una UpdateException');
});

test('versioni delle migrazioni fuori ordine sono un errore', function () {
    [, $m] = migrationSite([
        '0001_a' => "['version' => 2, 'up' => fn(\$f) => \$f]",
        '0002_b' => "['version' => 1, 'up' => fn(\$f) => \$f]",
    ]);
    try {
        $m->all();
    } catch (UpdateException) {
        assertTrue(true);
        return;
    }
    fail('mi aspettavo una UpdateException');
});

test('riconosce dati salvati da una versione piu recente', function () {
    [, $m] = migrationSite(['0001_a' => "['version' => 1, 'up' => fn(\$f) => \$f]"]);
    assertSame(true, $m->dataIsNewer(['data_version' => 2]));
    assertSame(false, $m->dataIsNewer(['data_version' => 1]));
    assertSame(false, $m->dataIsNewer([]));
});

// --- migrazioni reali -----------------------------------------------------

test('le migrazioni del framework sono valide e in ordine', function () {
    $m = new Migrator(sys_get_temp_dir(), KRIS_ROOT . '/kris/migrations');
    assertTrue(count($m->all()) >= 1, 'deve esserci almeno la 0001');
    assertTrue($m->latestDataVersion() >= 1);
});

test('0001 non cambia i contenuti e porta i dati alla versione 1', function () {
    [$root] = migrationSite([]);
    copy(KRIS_ROOT . '/kris/migrations/0001_stato_iniziale.php', $root . '/migrations/0001_stato_iniziale.php');
    $before = file_get_contents($root . '/data/k_data.json');
    $m = new Migrator($root, $root . '/migrations');
    $state = $m->run([]);
    assertTrue(in_array('0001_stato_iniziale', $state['migrations'], true));
    assertSame($before, file_get_contents($root . '/data/k_data.json'));
});
