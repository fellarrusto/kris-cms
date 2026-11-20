<?php
declare(strict_types=1);

/**
 * Archivio nel database (Kris\Store\PdoStore): lo stesso contratto
 * dell'adapter JSON, il passaggio fra adapter, le migrazioni e le copie di
 * sicurezza degli aggiornamenti. Su SQLite; con KRIS_TEST_PDO_DSN (più
 * KRIS_TEST_PDO_USER e KRIS_TEST_PDO_PASSWORD) sul database indicato, per
 * esempio MySQL nel job «mysql» della CI: ogni sito usa un prefisso proprio.
 */

use Kris\Store\ConflictException;
use Kris\Store\FileStore;
use Kris\Store\PdoStore;
use Kris\Store\Stores;
use Kris\Store\StoreException;

const PDO_TEST_DSN = 'KRIS_TEST_PDO_DSN';
$pdoDriver = getenv(PDO_TEST_DSN) ? strstr((string) getenv(PDO_TEST_DSN), ':', true) : 'sqlite';
if (!in_array($pdoDriver, PDO::getAvailableDrivers(), true)) {
    skipSuite("manca l'estensione pdo_{$pdoDriver} (php -d extension=pdo_{$pdoDriver} tests/run.php)");
    return;
}

/** Sito con i JSON di prova convertiti in un database SQLite, e config/store.php. */
function pdoSite(): array
{
    $root = sys_get_temp_dir() . '/kris-pdo-' . bin2hex(random_bytes(4));
    mkdir("{$root}/data", 0777, true);
    mkdir("{$root}/config", 0777, true);
    $docs = [
        'data' => json_decode((string) file_get_contents(KRIS_TESTS . '/fixtures/k_data.json'), true),
        'model' => json_decode((string) file_get_contents(KRIS_TESTS . '/fixtures/k_model.json'), true),
        'pages' => ['allowed_pages' => ['homepage']],
    ];
    $config = getenv(PDO_TEST_DSN)
        ? ['driver' => 'pdo', 'dsn' => (string) getenv(PDO_TEST_DSN), 'user' => (string) getenv('KRIS_TEST_PDO_USER'), 'password' => (string) getenv('KRIS_TEST_PDO_PASSWORD'), 'prefix' => 't' . bin2hex(random_bytes(4)) . '_']
        : ['driver' => 'pdo', 'dsn' => 'sqlite:data/kris.sqlite'];
    (new PdoStore($root, $config))->import($docs);
    file_put_contents("{$root}/config/store.php", '<?php return ' . var_export($config, true) . ';');
    register_shutdown_function(function () use ($root) {
        Stores::reset();
        gc_collect_cycles();
        rrmdir($root);
    });
    return [$root, $config, $docs];
}

test('database: lettura con revisione, documento assente vuoto, percorso relativo alla root', function () {
    [$root, $config, $docs] = pdoSite();
    $s = new PdoStore($root, $config);
    assertSame($docs['data'], $s->read('data')->value);
    assertSame('1', $s->read('data')->rev);
    assertSame('', $s->read('settings')->rev);
    assertSame([], $s->read('settings')->value);
    if (!getenv(PDO_TEST_DSN)) assertTrue(is_file("{$root}/data/kris.sqlite"), 'il file SQLite sta nella root del sito');
});

test('database: scrittura con revisione superata rifiutata, senza revisione riesce', function () {
    [$root, $config] = pdoSite();
    $s = new PdoStore($root, $config);
    $read = $s->read('pages');
    $rev = $s->write('pages', ['allowed_pages' => ['homepage', 'menu']], $read->rev);
    assertSame('2', $rev);
    try {
        $s->write('pages', ['allowed_pages' => ['homepage']], $read->rev);
        throw new RuntimeException('doveva essere un conflitto');
    } catch (ConflictException $e) {
        assertSame('pages', $e->doc);
        assertSame('2', $e->currentRev);
    }
    // Un secondo collegamento vede la stessa revisione
    assertSame(['homepage', 'menu'], (new PdoStore($root, $config))->read('pages')->value['allowed_pages']);
    $s->write('pages', ['allowed_pages' => ['homepage']]);
    assertSame(['homepage'], $s->read('pages')->value['allowed_pages']);
});

test('database: una transazione fallita non lascia niente, e scrive solo i documenti letti', function () {
    [$root, $config] = pdoSite();
    $s = new PdoStore($root, $config);
    try {
        $s->transaction(['data', 'model'], function (array $docs): array {
            return ['data' => [], 'seo' => ['head' => false]];
        });
        throw new RuntimeException('doveva essere rifiutata');
    } catch (StoreException $e) {
        assertContains('non ha letto', $e->getMessage());
    }
    assertTrue(count($s->read('data')->value) > 0, 'la scrittura di data è stata annullata');
    $s->transaction(['data', 'model'], function (array $docs): array {
        $docs['data'][0]['hidden'] = true;
        return ['data' => $docs['data']];
    });
    assertSame(true, $s->read('data')->value[0]['hidden']);
    assertSame('1', $s->read('model')->rev, 'il documento non cambiato non si riscrive');
});

test('database: un DSN sbagliato è un errore dell\'archivio, senza dettagli del collegamento', function () {
    try {
        new PdoStore(sys_get_temp_dir(), ['dsn' => 'mysql:host=127.0.0.1;port=1;dbname=x', 'password' => 'segreta']);
        throw new RuntimeException('doveva fallire');
    } catch (StoreException $e) {
        assertContains('config/store.php', $e->getMessage());
        assertTrue(!str_contains($e->getMessage(), 'segreta'));
    }
});

test('Stores sceglie il database da config/store.php e il passaggio torna ai file identico', function () {
    [$root, , $docs] = pdoSite();
    assertSame('pdo', Stores::driver($root));
    assertTrue(Stores::site($root) instanceof PdoStore);
    $exported = Stores::site($root)->export();
    $json = new FileStore($root);
    $json->import($exported);
    assertSame($exported, $json->export());
    assertSame($docs['data'], $json->read('data')->value);
});

test('database: le migrazioni passano dall\'archivio, non dai file', function () {
    [$root] = pdoSite();
    mkdir("{$root}/migrations");
    file_put_contents("{$root}/migrations/0001_campo.php", "<?php return ['version' => 1, 'up' => function (\$f) { \$f['k_model']['homepage'][] = ['name' => 'nuovo', 'type' => 'text']; \$f['cms_settings'] = ['languages' => ['it']]; return \$f; }];");
    $state = (new \Kris\Update\Migrator($root, "{$root}/migrations"))->run([]);
    assertSame(['0001_campo'], $state['migrations']);
    $store = Stores::site($root);
    $fields = $store->read('model')->value['homepage'];
    assertSame('nuovo', end($fields)['name']);
    assertSame(['languages' => ['it']], $store->read('cms_settings')->value);
    assertTrue(!is_file("{$root}/data/k_model.json"), 'nessun file JSON creato');
});

test('database: lo snapshot di un aggiornamento conserva i documenti e li rimette', function () {
    [$root] = pdoSite();
    require_once KRIS_ROOT . '/kris/update/installer.php';
    $snapshot = "{$root}/data/snapshots/prova";
    \Kris\Install\snapshot_create($root, $snapshot, ['id' => 'prova']);
    \Kris\Install\snapshot_store($root, $snapshot);
    $before = Stores::site($root)->export();
    Stores::site($root)->write('data', []);
    \Kris\Install\restore_files($snapshot, $root);
    assertSame($before, Stores::site($root)->export());
});
