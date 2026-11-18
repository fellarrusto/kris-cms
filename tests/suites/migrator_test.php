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

// --- 0009: formato 2 ----------------------------------------------------------

/** I documenti del formato 1 della demo, con pagine, SEO e template, convertiti dalla 0009. */
function formato2(array $override = []): array
{
    $m = require KRIS_ROOT . '/kris/migrations/0009_formato_2.php';
    $files = $override + [
        'k_data' => json_decode((string) file_get_contents(KRIS_TESTS . '/fixtures/k_data.json'), true),
        'k_model' => json_decode((string) file_get_contents(KRIS_TESTS . '/fixtures/k_model.json'), true),
        'cms_settings' => null,
        'allowed_pages' => ['allowed_pages' => ['homepage', 'detail', 'post', 'privacy']],
        'seo' => ['base_url' => 'https://x.it', 'sitemap' => [
            ['page' => 'homepage'],
            ['page' => 'detail', 'key' => 'homepage', 'id' => 0, 'children' => 'features'],
            ['page' => 'privacy', 'key' => 'kris_legal', 'id' => 0, 'priority' => 0.3],
        ], 'redirects' => ['/vecchio' => '/']],
        'templates' => ['post-card' => '<a href="index.php?page=post&amp;id=0&amp;path=posts/{{id}}&amp;ln={{language}}">x</a>'],
    ];
    return ($m['up'])($files);
}

test('0009: campi con tipo e localized, valori per nome, tipo solo nello schema', function () {
    $f = formato2();
    assertSame(['schema', 'content', 'settings'], array_keys($f));
    $fields = array_column($f['schema']['types']['homepage']['fields'], null, 'name');
    assertSame(['name' => 'code_example', 'type' => 'text', 'localized' => false, 'description' => 'Snippet di codice mostrato nella homepage'], $fields['code_example']);
    assertSame('media', $fields['logo']['type']);
    assertSame(true, $fields['logo']['localized']);
    assertSame('list', $fields['steps']['type']);
    assertTrue(!isset($fields['steps']['localized']), 'localized non vale per gli elenchi');
    $home = $f['content'][0];
    assertSame(['type', 'id', 'values'], array_keys($home));
    assertSame(['it' => 'Kris 2', 'en' => 'Kris 2'], $home['values']['page_title']);
    assertTrue(is_string($home['values']['code_example']));
    assertSame(['id', 'values'], array_keys($home['values']['steps'][0]));
    assertSame(['languages' => ['it', 'en'], 'seo' => ['base_url' => 'https://x.it']], $f['settings']);
    assertSame(['/vecchio' => '/'], $f['schema']['redirects']);
});

test('0009: gli elenchi con pagina (sitemap o link) diventano tipi con gli stessi id', function () {
    $f = formato2();
    assertSame(['homepage', 'features', 'posts', 'navbar', 'kris_legal'], array_keys($f['schema']['types']));
    $home = array_column($f['schema']['types']['homepage']['fields'], 'name');
    assertTrue(!in_array('features', $home, true) && !in_array('posts', $home, true));
    assertSame(true, $f['schema']['types']['posts']['newest_first'], 'posts: true diventa un elenco di articoli');
    assertSame(['homepage#0', 'features#0', 'features#1', 'features#2', 'posts#2', 'posts#1', 'posts#0', 'navbar#0', 'kris_legal#0'],
        array_map(fn($c) => "{$c['type']}#{$c['id']}", $f['content']));
    assertSame('list', array_column($f['schema']['types']['features']['fields'], 'type', 'name')['highlights'], 'gli elenchi annidati restano elenchi');
    assertSame([['from' => 'homepage', 'id' => 0, 'list' => 'features', 'to' => 'features'], ['from' => 'homepage', 'id' => 0, 'list' => 'posts', 'to' => 'posts']],
        $f['schema']['promoted']);
    assertSame([
        'homepage' => ['template' => 'homepage', 'type' => 'homepage', 'item' => 0, 'sitemap' => true],
        'detail' => ['template' => 'detail', 'type' => 'features', 'sitemap' => true],
        'post' => ['template' => 'post', 'type' => 'posts', 'sitemap' => false],
        'privacy' => ['template' => 'privacy', 'type' => 'kris_legal', 'item' => 0, 'sitemap' => true, 'priority' => 0.3],
    ], $f['schema']['pages']);
});

test('0009: un elenco di un tipo con più contenuti resta un elenco, con la sua pagina', function () {
    $f = formato2([
        'k_model' => ['progetti' => [['name' => 'foto', 'type' => 'array', 'of' => [['name' => 'img', 'type' => 'image']]]]],
        'k_data' => [
            ['id' => 0, 'name' => 'progetti', 'data' => [['name' => 'foto', 'type' => 'array', 'value' => [['id' => 0, 'data' => []]]]]],
            ['id' => 1, 'name' => 'progetti', 'hidden' => true, 'data' => []],
        ],
        'allowed_pages' => ['allowed_pages' => ['scatto']],
        'seo' => ['sitemap' => [['page' => 'scatto', 'key' => 'progetti', 'id' => 0, 'children' => 'foto']]],
        'templates' => [],
    ]);
    assertSame(['progetti'], array_keys($f['schema']['types']));
    assertSame(['template' => 'scatto', 'type' => 'progetti', 'item' => 0, 'list' => 'foto', 'sitemap' => true], $f['schema']['pages']['scatto']);
    assertSame(true, $f['content'][1]['hidden']);
    assertTrue(!isset($f['schema']['promoted']));
});

test('0009: interruttori, scelte, attributi delle raccolte; già nel formato 2 non cambia', function () {
    $f = formato2([
        'k_model' => ['menu' => [
            ['name' => 'attivo', 'type' => 'boolean'],
            ['name' => 'lingue', 'type' => 'boolean', 'localized' => true],
            ['name' => 'tag', 'type' => 'select', 'options' => ['a', 'b'], 'multiple' => true],
        ], 'pagina' => []],
        'k_data' => [['id' => 0, 'name' => 'menu', 'data' => [
            ['name' => 'attivo', 'type' => 'boolean', 'value' => '1'],
            ['name' => 'lingue', 'type' => 'boolean', 'value' => ['it' => '1', 'en' => '']],
            ['name' => 'tag', 'type' => 'select', 'value' => 'a,b'],
            ['name' => 'orfano', 'type' => 'plain', 'value' => 'resta'],
        ]]],
        'cms_settings' => ['languages' => ['it'], 'uploads' => ['webp' => true], 'collections' => ['menu' => ['label' => 'Menù', 'single' => true]],
            'collection_order' => ['pagina', 'menu'], 'post_collections' => ['pagina']],
        'allowed_pages' => null, 'seo' => null, 'templates' => [],
    ]);
    assertSame(['attivo' => true, 'lingue' => ['it' => true, 'en' => false], 'tag' => ['a', 'b'], 'orfano' => 'resta'], $f['content'][0]['values']);
    assertSame(['pagina', 'menu'], array_keys($f['schema']['types']));
    assertSame(['label' => 'Menù', 'single' => true], array_diff_key($f['schema']['types']['menu'], ['fields' => 1]));
    assertSame(true, $f['schema']['types']['pagina']['newest_first']);
    assertSame(['languages' => ['it'], 'uploads' => ['webp' => true]], $f['settings']);
    assertSame(['homepage' => ['template' => 'homepage', 'type' => 'homepage', 'item' => 0, 'sitemap' => true]], $f['schema']['pages']);
    $m = require KRIS_ROOT . '/kris/migrations/0009_formato_2.php';
    assertSame($f, ($m['up'])($f), 'idempotente');
});

test('0009 nel Migrator: scrive schema, content e settings dai documenti del formato 1', function () {
    [$root] = migrationSite([]);
    copy(KRIS_ROOT . '/kris/migrations/0009_formato_2.php', $root . '/migrations/0009_formato_2.php');
    $state = (new Migrator($root, $root . '/migrations'))->run([]);
    assertSame(9, $state['data_version']);
    $schema = json_decode((string) file_get_contents("{$root}/data/schema.json"), true);
    assertSame(2, $schema['format']);
    assertSame([['type' => 'homepage', 'id' => 0, 'values' => []]], json_decode((string) file_get_contents("{$root}/data/content.json"), true));
    assertSame(['languages' => ['it', 'en']], json_decode((string) file_get_contents("{$root}/data/settings.json"), true));
});
