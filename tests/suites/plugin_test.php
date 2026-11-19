<?php
declare(strict_types=1);

/**
 * Plugin (docs/2.0/plugin.md): manifest, installazione firmata con ripristino,
 * agganci della pagina con isolamento degli errori, endpoint, dati propri,
 * schermate dell'editor. Ogni test lavora su un sito temporaneo.
 */

use Kris\Plugin\Installer;
use Kris\Plugin\Manifest;
use Kris\Plugin\PluginException;
use Kris\Plugin\Plugins;
use Kris\Update\Signature;

if (!extension_loaded('sodium') || !extension_loaded('zip')) {
    skipSuite('servono le estensioni sodium e zip');
    return;
}
require_once KRIS_ROOT . '/tools/lib/release.php';

function pluginKeys(): array
{
    static $keys = null;
    return $keys ??= Signature::generateKeyPair();
}

/** Sito temporaneo con kris/, i template della repo e i dati delle fixture nel formato 2. */
function pluginSite(): string
{
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/kris-plugin-site-' . getmypid() . '-' . uniqid();
    mkdir($dir, 0777, true);
    copy(KRIS_ROOT . '/index.php', $dir . '/index.php');
    foreach (['kris', 'template'] as $folder) rcopy(KRIS_ROOT . '/' . $folder, $dir . '/' . $folder);
    mkdir($dir . '/config', 0777, true);
    writeFormat2($dir, format2Fixture());
    register_shutdown_function(fn() => rrmdir($dir));
    Plugins::useRoot($dir);
    return $dir;
}

/** Un plugin di prova: manifest, plugin.php e altri file (percorso => contenuto). */
function pluginPackage(array $manifest = [], ?string $code = null, array $files = [], ?string $secret = null): string
{
    $src = str_replace('\\', '/', sys_get_temp_dir()) . '/kris-plugin-src-' . uniqid();
    mkdir($src, 0777, true);
    $manifest += [
        'api' => 1, 'name' => 'prova', 'title' => 'Prova', 'description' => 'Plugin di prova', 'version' => '1.0.0',
        'requires' => ['kris' => '1.0.0', 'php' => '8.3'],
        'types' => ['plg_prova_prenotazioni'],
        'settings' => [['name' => 'codice', 'type' => 'text', 'localized' => false, 'description' => 'Codice']],
        'hooks' => ['head', 'body_end', 'block:saluto', 'published', 'content_saved'],
        'endpoints' => [['name' => 'invia', 'methods' => ['POST'], 'rate' => '2/1m', 'form_token' => true], ['name' => 'chiave', 'methods' => ['GET']]],
        'editor' => ['module' => 'editor/index.js', 'views' => [['name' => 'prenotazioni', 'title' => 'Prenotazioni']], 'dashboard' => 'prenotazioni', 'actions' => ['elenco']],
        'cookies' => [['name' => '_prova', 'category' => 'statistics', 'duration' => '1 anno', 'provider' => 'Prova', 'purpose' => 'Statistiche']],
        'domains' => ['api.prova.example'],
    ];
    file_put_contents("{$src}/plugin.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents("{$src}/plugin.php", $code ?? <<<'PHP'
<?php
defined('KRIS_ROOT') || exit;

use Kris\Plugin\Context;

return function (Context $kris): void {
    $kris->on('head', fn(array $page) => $kris->script('prova.js', 'statistics') . $kris->script('consenso.js', 'technical'));
    $kris->on('body_end', fn(array $page) => '<!-- prova ' . $page['status'] . ' ' . $page['lang'] . ' -->');
    $kris->on('block:saluto', fn(array $page, array $attrs) => $kris->render('saluto', ['nome' => $attrs['nome'] ?? 'mondo', 'codice' => $kris->settings()['codice'] ?? '']));
    $kris->on('endpoint:invia', function (array $req) use ($kris) {
        $kris->items('plg_prova_prenotazioni')->save(['values' => ['nome' => (string) ($req['body']['nome'] ?? '')]]);
        return ['status' => 303, 'location' => '/grazie'];
    });
    $kris->on('endpoint:chiave', fn(array $req) => ['status' => 200, 'text' => 'chiave-segreta']);
    $kris->on('editor:elenco', fn(array $req) => $kris->items('plg_prova_prenotazioni')->list());
    $kris->on('published', function (array $p) use ($kris) { $kris->log('pubblicati ' . count($p['files']) . ' file'); });
};
PHP);
    $files += [
        'templates/saluto.html' => '<p class="saluto">Ciao {{nome}}{{#if codice}} ({{codice}}){{/if}}</p>',
        'public/prova.js' => 'console.log("prova");',
        'public/consenso.js' => 'window.prova = 1;',
        'public/editor/index.js' => 'export default { views: {} };',
        'migrations/0001_prenotazioni.php' => "<?php\ndefined('KRIS_ROOT') || exit;\nreturn ['up' => function (array \$d): array {\n    \$d['schema']['types']['plg_prova_prenotazioni'] = ['fields' => [['name' => 'nome', 'type' => 'text', 'localized' => false]]];\n    return \$d;\n}];\n",
    ];
    foreach ($files as $path => $content) {
        if ($content === null) { @unlink("{$src}/{$path}"); continue; }
        if (!is_dir(dirname("{$src}/{$path}"))) mkdir(dirname("{$src}/{$path}"), 0777, true);
        file_put_contents("{$src}/{$path}", $content);
    }
    $zip = $src . '.zip';
    kris_build_plugin_package($src, $zip, $secret ?? pluginKeys()['secret']);
    rrmdir($src);
    register_shutdown_function(fn() => @unlink($zip));
    return $zip;
}

function pluginInstaller(): Installer
{
    return new Installer(['test' => pluginKeys()['public']], null, null, '2.0.0');
}

function assertPluginError(callable $fn, string $contains): void
{
    try {
        $fn();
    } catch (PluginException $e) {
        assertContains($contains, $e->getMessage());
        return;
    }
    fail('era attesa una PluginException');
}

function pluginDone(): void
{
    Plugins::useRoot(null);
    \Kris\Store\Stores::reset();
    \Kris\Store\Site::forget();
}

function sitePage(string $html, array $page = []): string
{
    return Plugins::decorate($html, $page + ['page' => 'homepage', 'lang' => 'it', 'status' => 200]);
}

test('manifest: nome, tipi con il prefisso, agganci dichiarati, tipo delle impostazioni', function () {
    $m = Manifest::parse((string) json_encode(['api' => 1, 'name' => 'misura', 'version' => '1.0.0', 'hooks' => ['head'],
        'settings' => [['name' => 'ga4_id', 'type' => 'text']], 'endpoints' => [['name' => 'k']]]));
    assertSame(['plg_misura_settings'], $m['types']);
    assertSame(['head', 'endpoint:k'], $m['hooks']);
    $cases = [
        [['api' => 2, 'name' => 'x1', 'version' => '1.0.0'], 'versione dei plugin'],
        [['api' => 1, 'name' => 'X', 'version' => '1.0.0'], 'nome'],
        [['api' => 1, 'name' => 'aa', 'version' => '1.0.0', 'types' => ['plg_bb_x']], 'prefisso'],
        [['api' => 1, 'name' => 'aa', 'version' => '1.0.0', 'hooks' => ['render']], 'aggancio sconosciuto'],
        [['api' => 1, 'name' => 'aa', 'version' => '1.0.0', 'editor' => ['views' => [['name' => 'v']]]], 'modulo'],
    ];
    foreach ($cases as [$m, $why]) assertPluginError(fn() => Manifest::parse((string) json_encode($m)), $why);
});

test('installa un plugin firmato: spento, file pubblici, tipi riservati, migrazione e impostazioni', function () {
    $site = pluginSite();
    try {
        $m = pluginInstaller()->installZip(pluginPackage(), 'prova', 'anna');
        assertSame('1.0.0', $m['version']);
        assertTrue(is_file("{$site}/plugins/prova/plugin.php"));
        assertTrue(is_file("{$site}/plugins/.htaccess"), 'plugins/ va negata ad Apache');
        assertSame('console.log("prova");', file_get_contents("{$site}/assets/plugins/prova/prova.js"));
        $state = Plugins::state()['plugins']['prova'];
        assertSame(false, $state['active']);
        assertSame(['0001_prenotazioni'], $state['migrations']);
        $schema = json_decode(file_get_contents("{$site}/data/schema.json"), true);
        assertSame(true, $schema['types']['plg_prova_prenotazioni']['reserved']);
        assertSame(true, $schema['types']['plg_prova_settings']['single']);
        assertSame([], Plugins::active());
    } finally {
        pluginDone();
    }
});

test('pacchetti rifiutati senza toccare il sito: firma, nome, PHP senza guardia o rotto, file pubblici non ammessi', function () {
    $site = pluginSite();
    try {
        $before = file_get_contents("{$site}/data/schema.json");
        $i = pluginInstaller();
        assertPluginError(fn() => $i->installZip(pluginPackage([], null, [], Signature::generateKeyPair()['secret']), 'prova', 'a'), 'firma');
        assertPluginError(fn() => $i->installZip(pluginPackage(), 'altro', 'a'), 'altro plugin');
        assertPluginError(fn() => $i->installZip(pluginPackage([], "<?php\nreturn function () {};\n"), 'prova', 'a'), "defined('KRIS_ROOT')");
        assertPluginError(fn() => $i->installZip(pluginPackage([], "<?php\ndefined('KRIS_ROOT') || exit;\nreturn function ( {;\n"), 'prova', 'a'), 'sintassi');
        assertPluginError(fn() => $i->installZip(pluginPackage([], null, ['public/x.php' => '<?php']), 'prova', 'a'), 'non ammesso');
        assertPluginError(fn() => $i->installZip(pluginPackage(['requires' => ['kris' => '9.0.0']]), 'prova', 'a'), 'Kris 9.0.0');
        assertSame($before, file_get_contents("{$site}/data/schema.json"));
        assertTrue(!is_dir("{$site}/plugins/prova") && !is_dir("{$site}/assets/plugins/prova"));
        assertSame([], Plugins::state()['plugins']);
    } finally {
        pluginDone();
    }
});

test('una migrazione che tocca dati non del plugin è rifiutata e tutto torna com\'era', function () {
    $site = pluginSite();
    try {
        $i = pluginInstaller();
        $i->installZip(pluginPackage(), 'prova', 'a');
        $before = file_get_contents("{$site}/data/schema.json");
        $bad = "<?php\ndefined('KRIS_ROOT') || exit;\nreturn ['up' => function (array \$d): array {\n    \$d['schema']['types']['homepage']['label'] = 'Rubata';\n    return \$d;\n}];\n";
        assertPluginError(fn() => $i->installZip(pluginPackage(['version' => '1.1.0'], null, ['migrations/0002_altro.php' => $bad]), 'prova', 'a'), 'non sono del plugin');
        assertSame($before, file_get_contents("{$site}/data/schema.json"));
        assertSame('1.0.0', Plugins::state()['plugins']['prova']['version']);
        assertSame('1.0.0', Manifest::read("{$site}/plugins/prova")['version'], 'la cartella del plugin torna quella di prima');
    } finally {
        pluginDone();
    }
});

test('acceso: head con script bloccati dal consenso, body_end, blocchi; spento il blocco sparisce', function () {
    $site = pluginSite();
    try {
        $i = pluginInstaller();
        $i->installZip(pluginPackage(), 'prova', 'a');
        $html = '<html><head></head><body><div k-plugin="prova:saluto" data-nome="Anna"></div></body></html>';
        $off = sitePage($html);
        assertNotContains('k-plugin', $off);
        assertNotContains('saluto', $off);
        $i->setActive('prova', true);
        $on = sitePage($html, ['status' => 404]);
        assertContains('<script type="text/plain" data-kris-consent-script="statistics" src="assets/plugins/prova/prova.js"></script>', $on);
        assertContains('<script src="assets/plugins/prova/consenso.js"></script>', $on);
        assertContains('<p class="saluto">Ciao Anna</p>', $on);
        assertContains('<!-- prova 404 it -->', $on);
        assertTrue(strpos($on, '<!-- prova') < strpos($on, '</body>'));
    } finally {
        pluginDone();
    }
});

test('un\'eccezione in un aggancio non rompe la pagina e finisce nel registro', function () {
    $site = pluginSite();
    try {
        $code = "<?php\ndefined('KRIS_ROOT') || exit;\nreturn function (\\Kris\\Plugin\\Context \$k): void {\n    \$k->on('head', function () { throw new RuntimeException('rotto'); });\n    \$k->on('body_end', fn() => '<i>ok</i>');\n};\n";
        $i = pluginInstaller();
        $i->installZip(pluginPackage([], $code), 'prova', 'a');
        $i->setActive('prova', true);
        $out = sitePage('<html><head></head><body></body></html>');
        assertContains('<i>ok</i>', $out);
        assertContains('rotto', Plugins::state()['plugins']['prova']['last_error']['message']);
        assertContains('rotto', implode("\n", Plugins::logLines('prova')));
        assertSame(['prova'], Plugins::active(), 'un\'eccezione non spegne il plugin');
    } finally {
        pluginDone();
    }
});

test('un errore fatale in un aggancio spegne il plugin; la pagina dopo è senza', function () {
    $site = pluginSite();
    try {
        $code = "<?php\ndefined('KRIS_ROOT') || exit;\nreturn function (\\Kris\\Plugin\\Context \$k): void {\n    \$k->on('head', function () { funzione_che_non_esiste_davvero(); });\n};\n";
        $i = pluginInstaller();
        $i->installZip(pluginPackage([], $code), 'prova', 'a');
        $i->setActive('prova', true);
        // Un errore fatale non si cattura: in un processo a parte, come una richiesta vera
        $first = renderPage([], $site);
        assertSame(200, $first['status']);
        Plugins::reset();
        $state = Plugins::state()['plugins']['prova'];
        // Una funzione mancante è un Error (catturato): il plugin resta acceso con l'errore nel registro
        assertContains('funzione_che_non_esiste_davvero', (string) ($state['last_error']['message'] ?? ''));
        $second = renderPage([], $site);
        assertSame(200, $second['status']);

        // Errore fatale vero (memoria esaurita): il plugin si spegne
        $fatal = "<?php\ndefined('KRIS_ROOT') || exit;\nreturn function (\\Kris\\Plugin\\Context \$k): void {\n    \$k->on('head', function () { ini_set('memory_limit', '8M'); \$a = []; while (true) \$a[] = str_repeat('x', 100000); });\n};\n";
        $i->installZip(pluginPackage(['version' => '1.0.1'], $fatal), 'prova', 'a');
        $i->setActive('prova', true);
        renderPage([], $site);
        Plugins::reset();
        $state = Plugins::state()['plugins']['prova'];
        assertSame(false, $state['active']);
        assertContains('memory', strtolower((string) $state['disabled_reason']));
        $after = renderPage([], $site);
        assertSame(200, $after['status']);
        assertSame(null, $after['fatal']);
        assertContains('Plugin disattivato', Plugins::alerts()[0]['text'] ?? '');
    } finally {
        pluginDone();
    }
});

test('endpoint: metodo, gettone del modulo, frequenza, redirect solo sul sito, testo', function () {
    $site = pluginSite();
    try {
        $i = pluginInstaller();
        $i->installZip(pluginPackage(), 'prova', 'a');
        $i->setActive('prova', true);
        $req = fn(array $body, string $method = 'POST', string $ip = '1.2.3.4') => Plugins::endpoint('prova', 'invia',
            ['method' => $method, 'query' => [], 'body' => $body, 'lang' => 'it', 'ip' => $ip, 'files' => [], 'size' => 10]);
        assertSame(405, $req([], 'GET')['status']);
        assertSame(403, $req(['nome' => 'x'])['status'], 'senza gettone');
        $token = Plugins::formToken('prova', 'invia');
        assertSame(['status' => 303, 'location' => '/grazie'], $req(['nome' => 'Anna', 'kris_form_token' => $token]));
        assertSame(429, $req(['nome' => 'Luca', 'kris_form_token' => $token])['status'], 'limite 2 al minuto, contato anche il rifiuto');
        assertSame(303, $req(['nome' => 'Bea', 'kris_form_token' => $token], 'POST', '5.6.7.8')['status'], 'un altro IP');
        assertTrue(!Plugins::validFormToken('prova', 'chiave', $token), 'il gettone vale per un endpoint');
        assertTrue(!Plugins::validFormToken('prova', 'invia', (time() - 8000) . '.' . str_repeat('a', 64)));
        $list = Plugins::editorAction('prova', 'elenco', ['method' => 'GET', 'query' => [], 'body' => []]);
        assertSame(['Anna', 'Bea'], array_map(fn($x) => $x['values']['nome'], $list));
        assertSame(['status' => 200, 'text' => 'chiave-segreta'], Plugins::endpoint('prova', 'chiave', ['method' => 'GET', 'query' => [], 'body' => [], 'lang' => 'it', 'ip' => '1', 'files' => [], 'size' => 0]));
        assertSame(404, Plugins::endpoint('prova', 'nessuno', ['method' => 'GET', 'query' => [], 'body' => [], 'lang' => 'it', 'ip' => '1'])['status']);
        assertSame(413, Plugins::endpoint('prova', 'invia', ['method' => 'POST', 'query' => [], 'body' => [], 'lang' => 'it', 'ip' => '8.8.8.8', 'files' => [], 'size' => 70000])['status']);
        assertPluginError(fn() => Plugins::editorAction('prova', 'cancella', []), 'non esiste');
    } finally {
        pluginDone();
    }
});

test('il redirect di un endpoint verso un altro dominio è rifiutato', function () {
    $site = pluginSite();
    try {
        $code = "<?php\ndefined('KRIS_ROOT') || exit;\nreturn function (\\Kris\\Plugin\\Context \$k): void {\n    \$k->on('endpoint:chiave', fn() => ['status' => 302, 'location' => '//evil.example/x']);\n};\n";
        $i = pluginInstaller();
        $i->installZip(pluginPackage([], $code), 'prova', 'a');
        $i->setActive('prova', true);
        assertSame(500, Plugins::endpoint('prova', 'chiave', ['method' => 'GET', 'query' => [], 'body' => [], 'lang' => 'it', 'ip' => '1', 'files' => [], 'size' => 0])['status']);
    } finally {
        pluginDone();
    }
});

test('dati del plugin: save, list, atomic tutto o niente; le operazioni non li toccano', function () {
    $site = pluginSite();
    try {
        $i = pluginInstaller();
        $i->installZip(pluginPackage(), 'prova', 'a');
        $items = new \Kris\Plugin\Items('plg_prova_prenotazioni');
        $a = $items->save(['values' => ['nome' => 'A']]);
        $b = $items->save(['values' => ['nome' => 'B']]);
        assertSame([0, 1], [$a['id'], $b['id']]);
        try {
            $items->atomic(function ($it) {
                $it->save(['values' => ['nome' => 'C']]);
                throw new RuntimeException('annulla');
            });
        } catch (RuntimeException) {
        }
        assertSame(['A', 'B'], array_map(fn($x) => $x['values']['nome'], $items->list()));
        $items->delete(0);
        assertSame([1], array_column($items->list(), 'id'));
        assertSame([['id' => 1, 'hidden' => false, 'values' => ['nome' => 'B']]], $items->list(['nome' => 'B']));
        try {
            \Kris\Staging\Operations::apply(['schema' => ['types' => []], 'content' => [['type' => 'plg_prova_prenotazioni', 'id' => 1, 'values' => []]], 'settings' => []],
                ['op' => 'set_field', 'key' => 'plg_prova_prenotazioni', 'id' => 1, 'field' => 'nome', 'value' => 'x'], ['it'], fn() => true);
            fail('attesa StagingException');
        } catch (\Kris\Staging\StagingException $e) {
            assertContains('plugin', $e->getMessage());
        }
    } finally {
        pluginDone();
    }
});

test('modalità sicura: nessun plugin gira, lo stato resta', function () {
    $site = pluginSite();
    try {
        $i = pluginInstaller();
        $i->installZip(pluginPackage(), 'prova', 'a');
        $i->setActive('prova', true);
        file_put_contents("{$site}/config/plugins.php", "<?php return ['safe_mode' => true];");
        Plugins::reset();
        assertSame([], Plugins::active());
        assertNotContains('prova', sitePage('<html><head></head><body></body></html>'));
        assertSame(true, Plugins::state()['plugins']['prova']['active']);
        assertContains('Modalità sicura', Plugins::alerts()[0]['text']);
    } finally {
        pluginDone();
    }
});

test('cookie dichiarati, avviso senza banner, rimozione con i dati', function () {
    $site = pluginSite();
    try {
        $i = pluginInstaller();
        $i->installZip(pluginPackage(), 'prova', 'a');
        $i->setActive('prova', true);
        assertSame('_prova', Plugins::cookies()[0]['name']);
        assertSame('Prova', Plugins::cookies()[0]['plugin']);
        $banner = (bool) array_filter(glob("{$site}/template/*.html"), fn($f) => str_contains(file_get_contents($f), 'data-kris-consent-banner'));
        assertSame(!$banner, (bool) array_filter(Plugins::alerts(), fn($a) => str_contains($a['text'], 'banner')));
        (new \Kris\Plugin\Items('plg_prova_prenotazioni'))->save(['values' => ['nome' => 'A']]);
        $i->remove('prova', true);
        assertTrue(!is_dir("{$site}/plugins/prova") && !is_dir("{$site}/assets/plugins/prova"));
        $schema = json_decode(file_get_contents("{$site}/data/schema.json"), true);
        assertTrue(!isset($schema['types']['plg_prova_prenotazioni']) && !isset($schema['types']['plg_prova_settings']));
        assertSame([], array_values(array_filter(json_decode(file_get_contents("{$site}/data/content.json"), true), fn($c) => str_starts_with($c['type'], 'plg_'))));
    } finally {
        pluginDone();
    }
});

test('con modifiche in attesa non si installa', function () {
    $site = pluginSite();
    try {
        (new \Kris\Staging\Staging($site))->write('template/nuovo.html', '<p>x</p>', null, 'test');
        assertPluginError(fn() => pluginInstaller()->installZip(pluginPackage(), 'prova', 'a'), 'in attesa');
    } finally {
        pluginDone();
    }
});

test('catalogo e versioni: solo firmati, solo dalle repository ufficiali', function () {
    $site = pluginSite();
    try {
        $keys = pluginKeys();
        $catalog = json_encode(['plugins' => [['name' => 'prova', 'title' => 'Prova']]]);
        $releases = json_encode(['releases' => [
            ['version' => '1.0.0', 'zip' => 'https://github.com/fellarrusto/kris-plugin-prova/releases/download/v1.0.0/prova.zip', 'sha256' => str_repeat('a', 64), 'requires' => ['kris' => '2.0.0', 'php' => '8.3']],
            ['version' => '1.1.0', 'zip' => 'https://github.com/altri/kris-plugin-prova/releases/download/v1.1.0/prova.zip', 'sha256' => str_repeat('b', 64)],
            ['version' => '3.0.0', 'zip' => 'https://github.com/fellarrusto/kris-plugin-prova/releases/download/v3.0.0/prova.zip', 'sha256' => str_repeat('c', 64), 'requires' => ['kris' => '3.0.0']],
        ]]);
        $responses = [
            Installer::CATALOG => $catalog, Installer::CATALOG . '.sig' => Signature::sign($catalog, $keys['secret']),
            'https://raw.githubusercontent.com/fellarrusto/kris-plugin-prova/main/releases.json' => $releases,
            'https://github.com/fellarrusto/kris-plugin-prova/releases/download/v1.0.0/prova.zip' => 'non uno zip',
            'https://raw.githubusercontent.com/fellarrusto/kris-plugin-prova/main/releases.json.sig' => Signature::sign($releases, $keys['secret']),
        ];
        $http = new class($responses) extends \Kris\Update\Http {
            public function __construct(private array $r) { parent::__construct(); }
            public function get(string $url, int $maxBytes): string { return $this->r[$url] ?? throw new \Kris\Update\UpdateException("404 {$url}"); }
        };
        $i = new Installer(['test' => $keys['public']], $http, null, '2.0.0');
        assertSame('prova', $i->catalog()[0]['name']);
        $list = $i->releases('prova');
        assertSame(['3.0.0', '1.0.0'], array_column($list, 'version'), 'la versione da un\'altra repository non c\'è');
        assertSame('1.0.0', $i->latestCompatible($list)['version']);
        $wrong = new Installer(['test' => Signature::generateKeyPair()['public']], $http, null, '2.0.0');
        assertPluginError(fn() => $wrong->catalog(), 'firma');
        assertPluginError(fn() => $i->install('prova', null, 'a'), 'non corrisponde');
    } finally {
        pluginDone();
    }
});
