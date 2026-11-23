<?php
declare(strict_types=1);

/**
 * Indirizzi leggibili (Kris\Site\Router): rotte dalle regole della sitemap,
 * generazione, risoluzione, riscrittura dei link e 301 dai vecchi indirizzi.
 */

use Kris\Site\Router;

$demoRoutes = fn(): array => Router::routes(['pages' => [
    'homepage' => ['template' => 'homepage', 'type' => 'homepage', 'item' => 0],
    'detail' => ['template' => 'detail', 'type' => 'homepage', 'item' => 0, 'list' => 'features'],
    'post' => ['template' => 'post', 'type' => 'post', 'item' => 0],
    'privacy' => ['template' => 'privacy', 'type' => 'kris_legal', 'item' => 0],
    'progetto' => ['template' => 'progetto', 'type' => 'progetti'],
]]);

test('le rotte vengono dalle pagine dello schema: contenuto fisso, tipo, elenco', function () use ($demoRoutes) {
    $r = $demoRoutes();
    assertSame('single', $r['homepage']['kind']);
    assertSame(['key' => 'homepage', 'kind' => 'children', 'id' => 0, 'children' => 'features'], $r['detail']);
    assertSame('collection', $r['progetto']['kind']);
    assertSame(['key' => 'post', 'kind' => 'single', 'id' => 0, 'children' => ''], $r['post']);
});

test('un elenco promosso a tipo: i vecchi link portano al contenuto del tipo', function () {
    $r = Router::routes([
        'pages' => ['post' => ['template' => 'post', 'type' => 'posts', 'sitemap' => true]],
        'promoted' => [['from' => 'homepage', 'id' => 0, 'list' => 'posts', 'to' => 'posts']],
    ]);
    assertSame('/post/2', Router::path($r, 'post', 'posts', 2, [], 'it', 'it'));
    assertSame('/post/2', Router::path($r, 'post', 'homepage', 0, ['posts', '2'], 'it', 'it'));
    assertSame(null, Router::path($r, 'post', 'homepage', 0, ['steps', '2'], 'it', 'it'));
    assertSame(['page' => 'post', 'key' => 'posts', 'id' => '2'], Router::resolve($r, '/post/2', ['it'], 'it'));
});

test('ogni rotta ha il suo percorso, nella lingua principale senza prefisso', function () use ($demoRoutes) {
    $r = $demoRoutes();
    assertSame('/', Router::path($r, 'homepage', 'homepage', 0, [], 'it', 'it'));
    assertSame('/en/', Router::path($r, 'homepage', 'homepage', 0, [], 'en', 'it'));
    assertSame('/privacy', Router::path($r, 'privacy', 'kris_legal', 0, [], 'it', 'it'));
    assertSame('/en/progetto/3', Router::path($r, 'progetto', 'progetti', 3, [], 'en', 'it'));
    assertSame('/detail/2', Router::path($r, 'detail', 'homepage', 0, ['features', '2'], 'it', 'it'));
    // Quello che la rotta non sa esprimere resta un indirizzo index.php?…
    assertSame(null, Router::path($r, 'detail', 'homepage', 0, ['steps', '2'], 'it', 'it'));
    assertSame(null, Router::path($r, 'privacy', 'homepage', 0, [], 'it', 'it'));
    assertSame(null, Router::path($r, 'sconosciuta', 'homepage', 0, [], 'it', 'it'));
});

test('un percorso torna ai parametri della pagina; uno sconosciuto e null', function () use ($demoRoutes) {
    $r = $demoRoutes();
    $langs = ['it', 'en'];
    assertSame(['page' => 'homepage'], Router::resolve($r, '/', $langs, 'it'));
    assertSame(['ln' => 'en', 'page' => 'homepage'], Router::resolve($r, '/en/', $langs, 'it'));
    assertSame(['ln' => 'en', 'page' => 'progetto', 'key' => 'progetti', 'id' => '3'], Router::resolve($r, '/en/progetto/3', $langs, 'it'));
    assertSame(['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/2'], Router::resolve($r, '/detail/2', $langs, 'it'));
    assertSame(['page' => 'privacy', 'key' => 'kris_legal', 'id' => '0'], Router::resolve($r, '/privacy', $langs, 'it'));
    foreach (['/privacy/1', '/progetto', '/progetto/abc', '/nessuna', '/homepage', '/it/privacy'] as $bad) {
        assertSame(null, Router::resolve($r, $bad, $langs, 'it'), $bad);
    }
});

test('i link index.php diventano percorsi leggibili; risorse e link della sottocartella partono dalla base', function () use ($demoRoutes) {
    $html = '<a href="index.php?page=progetto&amp;key=progetti&amp;id=3&amp;ln=en#top">x</a>'
        . '<a href="?page=privacy">y</a><a href="index.php?page=progetto&amp;key=progetti&amp;id=3&amp;utm_source=qr">z</a>'
        . '<link href="assets/css/style.css?v=1"><img src="./assets/uploads/a.webp"><a href="#c">c</a><a href="https://x.it">e</a><a href="/assoluto">a</a>';
    $out = Router::rewriteLinks($html, $demoRoutes(), '/sito', 'it');
    assertContains('href="/sito/en/progetto/3#top"', $out);
    assertContains('href="/sito/progetto/3?utm_source=qr"', $out, 'gli altri parametri restano in coda');
    assertContains('href="/sito/assets/css/style.css?v=1"', $out);
    assertContains('src="/sito/assets/uploads/a.webp"', $out);
    assertContains('href="#c"', $out);
    assertContains('href="https://x.it"', $out);
    assertContains('href="/assoluto"', $out);
});

test('un link senza key e id usa il tipo e il contenuto fisso della pagina', function () use ($demoRoutes) {
    $out = Router::rewriteLinks('<a href="index.php?page=privacy&amp;ln=en">p</a><a href="index.php?page=privacy&amp;servizio=social&amp;x=a%20b">q</a>', $demoRoutes(), '', 'it');
    assertContains('href="/en/privacy"', $out);
    assertContains('href="/privacy?servizio=social&amp;x=a%20b"', $out);
    assertSame(['page' => 'privacy', 'key' => 'kris_legal', 'id' => '0'], Router::fill($demoRoutes(), ['page' => 'privacy']));
    assertSame(['page' => 'progetto', 'key' => 'progetti'], Router::fill($demoRoutes(), ['page' => 'progetto']), 'una pagina per contenuto non sceglie l id');
});

test('con pretty_urls il sito risponde ai percorsi, i vecchi indirizzi fanno 301 e i link sono leggibili', function () {
    $root = sys_get_temp_dir() . '/kris-router-' . bin2hex(random_bytes(4));
    rcopy(projectSandbox(), $root);
    register_shutdown_function(fn() => rrmdir($root));
    $seo = json_decode((string) file_get_contents("{$root}/config/seo.json"), true);
    file_put_contents("{$root}/config/seo.json", json_encode($seo + ['pretty_urls' => true]));
    try {
        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/en/detail/0';
        $detail = renderOnce([], $root);
        assertSame(200, $detail['status']);
        assertContains('<html lang="en"', $detail['output']);
        assertContains('rel="canonical" href="http://localhost/en/detail/0"', $detail['output']);
        assertContains('href="/assets/css/', $detail['output'], 'le risorse partono dalla radice del sito');

        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/';
        $home = renderOnce([], $root);
        assertSame(200, $home['status']);
        assertContains('href="/detail/0"', $home['output'], 'il link della card è leggibile');

        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/nessuna-pagina';
        assertSame(404, renderOnce([], $root)['status']);

        unset($GLOBALS['KRIS_TEST_REQUEST_URI']);
        assertSame(301, renderOnce(['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/0'], $root)['status']);
        assertSame(200, renderOnce(['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'steps/0'], $root)['status'], 'senza rotta resta com è');
    } finally {
        unset($GLOBALS['KRIS_TEST_REQUEST_URI']);
    }
});

test('il cambio lingua su un percorso leggibile porta alla stessa pagina nell altra lingua', function () {
    $root = sys_get_temp_dir() . '/kris-router-' . bin2hex(random_bytes(4));
    rcopy(projectSandbox(), $root);
    register_shutdown_function(fn() => rrmdir($root));
    $seo = json_decode((string) file_get_contents("{$root}/config/seo.json"), true);
    file_put_contents("{$root}/config/seo.json", json_encode($seo + ['pretty_urls' => true]));
    try {
        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/detail/0?ln=en';
        assertSame(302, renderOnce(['ln' => 'en'], $root)['status']);
        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/en/detail/0?ln=en';
        assertSame(200, renderOnce(['ln' => 'en'], $root)['status'], 'già nella lingua: nessun redirect');
    } finally {
        unset($GLOBALS['KRIS_TEST_REQUEST_URI']);
    }
});

test('gli slug vengono dal campo della regola; vuoto vale l id, un doppione riceve -id', function () {
    $routes = Router::routes(['pages' => ['progetto' => ['template' => 'progetto', 'type' => 'progetti', 'slug' => 'url']]]);
    $routes['progetto']['slugs'] = [
        3 => ['it' => Router::slug('Casa Rossi, è qui'), 'en' => 'rossi-house'],
        5 => ['it' => '', 'en' => ''],
        7 => ['it' => 'casa-rossi-e-qui', 'en' => ''],
    ];
    assertSame('/progetto/casa-rossi-e-qui', Router::path($routes, 'progetto', 'progetti', 3, [], 'it', 'it'));
    assertSame('/en/progetto/rossi-house', Router::path($routes, 'progetto', 'progetti', 3, [], 'en', 'it'));
    assertSame('/progetto/5', Router::path($routes, 'progetto', 'progetti', 5, [], 'it', 'it'));
    assertSame('/progetto/casa-rossi-e-qui-7', Router::path($routes, 'progetto', 'progetti', 7, [], 'it', 'it'));
    $langs = ['it', 'en'];
    assertSame('3', Router::resolve($routes, '/progetto/casa-rossi-e-qui', $langs, 'it')['id']);
    assertSame('7', Router::resolve($routes, '/progetto/casa-rossi-e-qui-7', $langs, 'it')['id']);
    assertSame('3', Router::resolve($routes, '/en/progetto/rossi-house', $langs, 'it')['id']);
    assertSame('3', Router::resolve($routes, '/progetto/3', $langs, 'it')['id'], 'l id funziona sempre');
    assertSame(null, Router::resolve($routes, '/progetto/rossi-house', $langs, 'it'), 'slug di un altra lingua');
    assertSame('', Router::slug('2026'), 'solo cifre: si confonderebbe con un id');
});

test('i reindirizzamenti di seo.json rispondono con un 301', function () {
    $root = sys_get_temp_dir() . '/kris-router-' . bin2hex(random_bytes(4));
    rcopy(projectSandbox(), $root);
    register_shutdown_function(fn() => rrmdir($root));
    $seo = json_decode((string) file_get_contents("{$root}/config/seo.json"), true);
    file_put_contents("{$root}/config/seo.json", json_encode($seo + ['redirects' => ['/menu' => '/?ln=en', '/x' => 'javascript:alert(1)', '/project-detail/*' => '/progetti']]));
    try {
        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/menu/';
        assertSame(301, renderOnce([], $root)['status']);
        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/x';
        assertTrue(renderOnce([], $root)['status'] !== 301, 'solo percorsi del sito o https');
        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/project-detail/8';
        $r = renderOnce([], $root);
        assertSame([301, '/progetti'], [$r['status'], $r['location']], '/* vale per tutto quello sotto');
        $GLOBALS['KRIS_TEST_REQUEST_URI'] = '/project-detail';
        assertTrue(renderOnce([], $root)['status'] !== 301, '/* non vale per il prefisso stesso');
    } finally {
        unset($GLOBALS['KRIS_TEST_REQUEST_URI']);
    }
});

test('su Apache senza la regola del .htaccess di Kris restano gli indirizzi di prima', function () {
    $root = sys_get_temp_dir() . '/kris-rw-' . bin2hex(random_bytes(3));
    mkdir($root, 0777, true);
    register_shutdown_function(fn() => rrmdir($root));
    $server = $_SERVER['SERVER_SOFTWARE'] ?? null;
    try {
        unset($_SERVER['SERVER_SOFTWARE']);
        assertTrue(\Kris\Template\Seo::rewriteAvailable($root), 'server di sviluppo o Nginx: si presume configurato');
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
        assertSame(false, \Kris\Template\Seo::rewriteAvailable($root), 'Apache senza .htaccess');
        file_put_contents("{$root}/.htaccess", \Kris\Update\Stubs::HTACCESS);
        assertTrue(\Kris\Template\Seo::rewriteAvailable($root . '/'), 'Apache con la regola di Kris');
    } finally {
        if ($server === null) unset($_SERVER['SERVER_SOFTWARE']); else $_SERVER['SERVER_SOFTWARE'] = $server;
    }
});
