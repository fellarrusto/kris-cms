<?php
declare(strict_types=1);

/**
 * Pagine pubbliche servite da index.php, rese in un processo isolato
 * contro le fixture congelate (mai contro data/).
 *
 * Gli snapshot fotografano l'HTML di OGGI, artefatti inclusi: servono a
 * far emergere ogni cambiamento non voluto. Quando una modifica intenzionale
 * modifica l'output di proposito, si rigenerano con --update-snapshots.
 */

// --- comportamento HTTP --------------------------------------------------

test('la homepage risponde 200', function () {
    assertSame(200, renderPage([])['status']);
});

test('una pagina fuori whitelist risponde 404', function () {
    $r = renderPage(['page' => 'pagina_inventata']);
    assertSame(404, $r['status']);
    assertContains('Page Not Found', $r['output']);
});

test('un percorso nested inesistente risponde 404', function () {
    $r = renderPage(['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/999']);
    assertSame(404, $r['status']);
});

test('nessuna pagina pubblica produce un errore fatale', function () {
    foreach ([[], ['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/0']] as $q) {
        $r = renderPage($q);
        assertSame(null, $r['fatal'], 'errore fatale su ' . json_encode($q));
    }
});

// --- contenuto renderizzato ---------------------------------------------

test('la homepage elenca le tre feature', function () {
    $out = renderPage([])['output'];
    assertSame(3, substr_count($out, 'class="feature-item"'));
});

test('ogni feature linka il proprio dettaglio', function () {
    $out = renderPage([])['output'];
    foreach ([0, 1, 2] as $i) {
        assertContains('href="/detail/' . $i . '"', $out);
    }
});

test('il dettaglio di una feature mostra i suoi highlight', function () {
    $out = renderPage(['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/0'])['output'];
    assertTrue(substr_count($out, 'class="highlight-item"') > 0, 'nessun highlight renderizzato');
});

test('il componente navbar viene iniettato', function () {
    assertContains('class="header"', renderPage([])['output']);
});

test('la lingua richiesta cambia i contenuti', function () {
    $it = renderPage([])['output'];
    $en = renderPage(['ln' => 'en'])['output'];
    assertTrue($it !== $en, 'la pagina in inglese e identica a quella in italiano');
});

// --- blog (elenco marcato "posts" nello schema) --------------------------

test("la homepage elenca i post nell'ordine dei dati", function () {
    $out = renderPage([])['output'];
    assertSame(3, substr_count($out, 'class="post-card"'));
    assertTrue(strpos($out, 'href="/post/2"') < strpos($out, 'href="/post/0"'), 'il post piu recente non e in cima');
});

test('un post risponde 200 e mostra il testo formattato', function () {
    $r = renderPage(['page' => 'post', 'key' => 'homepage', 'id' => '0', 'path' => 'posts/1']);
    assertSame(200, $r['status']);
    assertContains('<li>Schema e dati insieme</li>', $r['output']);
});

test("un post senza copertina non produce un'immagine vuota", function () {
    $out = renderPage(['page' => 'post', 'key' => 'homepage', 'id' => '0', 'path' => 'posts/0'])['output'];
    assertNotContains('class="post-cover"', $out);
});

// --- snapshot ------------------------------------------------------------

test('snapshot: homepage (it)', function () {
    assertMatchesSnapshot('homepage_it', renderPage([])['output']);
});

test('snapshot: homepage (en)', function () {
    assertMatchesSnapshot('homepage_en', renderPage(['ln' => 'en'])['output']);
});

test('snapshot: post posts/2', function () {
    assertMatchesSnapshot('post_posts_2', renderPage([
        'page' => 'post', 'key' => 'homepage', 'id' => '0', 'path' => 'posts/2',
    ])['output']);
});

test('snapshot: dettaglio features/0', function () {
    assertMatchesSnapshot('detail_features_0', renderPage([
        'page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/0',
    ])['output']);
});

test('snapshot: dettaglio features/1', function () {
    assertMatchesSnapshot('detail_features_1', renderPage([
        'page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/1',
    ])['output']);
});

test('snapshot: dettaglio features/2', function () {
    assertMatchesSnapshot('detail_features_2', renderPage([
        'page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/2',
    ])['output']);
});

// --- bug noti, da correggere --------------------------------------------

test("un'entita inesistente risponde 404, non 500", function () {
    $r = renderPage(['key' => 'gruppo_inesistente', 'id' => '999']);
    assertSame(null, $r['fatal'], 'errore fatale invece del 404: ' . (string) $r['fatal']);
    assertSame(404, $r['status']);
});

test('il parametro ln non deve poter entrare nel markup', function () {
    $r = renderPage([
        'page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/0',
        'ln'   => 'x" data-iniettato="1',
    ]);
    assertNotContains('data-iniettato=', $r['output'], 'il parametro ln ha creato un attributo');
});

test('la lingua del documento segue quella richiesta', function () {
    assertContains('<html lang="en"', renderPage(['ln' => 'en'])['output']);
});

// Non e un bug: con una lingua sconosciuta getData() ricade sulla prima
// traduzione compilata, quindi la pagina resta leggibile. Il test blocca
// questo comportamento, perche la validazione di ln non deve toglierlo.
test('una lingua sconosciuta ricade sui contenuti disponibili', function () {
    $out = renderPage(['ln' => 'zz'])['output'];
    assertSame(3, substr_count($out, 'class="feature-item"'), 'la pagina ha perso i contenuti');
    assertContains('class="hero-title"', $out);
});

test('{{tipo.campo}} legge il primo contenuto di un altra raccolta, anche nelle condizioni', function () {
    $root = sys_get_temp_dir() . '/kris-globali-' . bin2hex(random_bytes(4));
    rcopy(projectSandbox(), $root);
    register_shutdown_function(fn() => rrmdir($root));
    $home = (string) file_get_contents("{$root}/template/homepage.html");
    file_put_contents("{$root}/template/homepage.html", str_replace('<body>', '<body><p id="g">[{{navbar.page_title}}]</p><p>{{#if navbar.page_title}}SI{{#else}}NO{{/if}}{{#if nessuna.cosa}}X{{/if}}</p>', $home));
    $out = renderPage([], $root)['output'];
    assertContains('<p id="g">[Kris 2]</p>', $out);
    assertContains('<p>SI</p>', $out);
});

function pageSandboxCopy(): string
{
    $root = sys_get_temp_dir() . '/kris-page-' . bin2hex(random_bytes(4));
    rcopy(projectSandbox(), $root);
    register_shutdown_function(fn() => rrmdir($root));
    return $root;
}

test('template/kris_head.html entra prima di </head> in ogni pagina e nella 404 del tema', function () {
    $root = pageSandboxCopy();
    file_put_contents("{$root}/template/kris_head.html", '<meta name="kris-test" content="{{page_title}}"><script type="application/ld+json">{"@type":"Organization"}</script>');
    $out = renderPage([], $root)['output'];
    $meta = strpos($out, '<meta name="kris-test" content="Kris 2">');
    assertTrue($meta !== false, 'il frammento c è, con il campo della pagina');
    assertTrue($meta < strpos($out, '</head>'), 'prima di </head>');
    assertContains('application/ld+json', $out);
    // Anche nella 404 del tema; quella di serie di Kris non usa i template
    file_put_contents("{$root}/template/404.html", '<!doctype html><html><head><title>404</title></head><body>{{title}}</body></html>');
    assertContains('name="kris-test"', renderPage(['page' => 'inesistente'], $root)['output']);
});

test('lingua automatica: la homepage senza lingua segue cookie e browser, non i motori di ricerca', function () {
    $root = pageSandboxCopy();
    $seo = json_decode((string) file_get_contents("{$root}/config/seo.json"), true);
    file_put_contents("{$root}/config/seo.json", json_encode($seo + ['auto_language' => true]));
    try {
        $GLOBALS['KRIS_TEST_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9,it;q=0.5';
        assertSame(302, renderOnce([], $root)['status'], 'browser in inglese');
        assertSame(200, renderOnce([], $root, 'index.php', false, ['kris_lang' => 'it'])['status'], 'scelta già fatta: italiano');
        $en = renderPage(['ln' => 'en'], $root);
        assertSame([200, [[301, '/en/']]], [$en['status'], $en['redirects']], 'lingua nell indirizzo: solo il 301 al percorso, nessun redirect della lingua automatica');
        $privacy = renderPage(['page' => 'privacy', 'key' => 'kris_legal'], $root);
        assertSame([200, [[301, '/privacy']]], [$privacy['status'], $privacy['redirects']], 'solo la homepage');
        $GLOBALS['KRIS_TEST_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1)';
        assertSame(200, renderOnce([], $root)['status'], 'i motori di ricerca vedono la lingua principale');
        unset($GLOBALS['KRIS_TEST_USER_AGENT']);
        $GLOBALS['KRIS_TEST_ACCEPT_LANGUAGE'] = 'de-DE,fr;q=0.8';
        assertSame(200, renderOnce([], $root)['status'], 'lingua non attiva: la principale');
    } finally {
        unset($GLOBALS['KRIS_TEST_ACCEPT_LANGUAGE'], $GLOBALS['KRIS_TEST_USER_AGENT']);
    }
});

test('lavori in corso: i visitatori ricevono la pagina 503, chi ha l editor aperto vede il sito', function () {
    $root = pageSandboxCopy();
    $migration = require KRIS_ROOT . '/kris/migrations/0007_lavori_in_corso.php';
    $files = ['k_data' => json_decode((string) file_get_contents("{$root}/data/k_data.json"), true), 'k_model' => json_decode((string) file_get_contents("{$root}/data/k_model.json"), true), 'cms_settings' => []];
    $files = $migration['up']($files);
    assertSame($files, $migration['up']($files), 'la migrazione è idempotente');
    assertSame(200, renderPage([], $root)['status'], 'con l interruttore spento non cambia nulla');
    assertSame(200, renderPage(['kris_wip_preview' => '1'], $root)['status'], 'senza editor il parametro non conta');
    foreach ($files['k_data'] as &$entity) {
        if ($entity['name'] !== 'kris_wip') continue;
        foreach ($entity['data'] as &$field) if ($field['name'] === 'enabled') $field['value'] = '1';
    }
    unset($entity, $field);
    file_put_contents("{$root}/data/k_data.json", json_encode($files['k_data']));
    file_put_contents("{$root}/data/k_model.json", json_encode($files['k_model']));

    $guest = renderPage([], $root);
    assertSame(503, $guest['status']);
    assertContains('Sito in lavorazione', $guest['output']);
    assertContains('noindex', $guest['output']);
    $admin = renderPage([], $root, 'index.php', true);
    assertSame(200, $admin['status'], 'l admin vede il sito');
    assertContains('Lavori in corso attivi', $admin['output'], 'con una striscia che ricorda che i visitatori non lo vedono');
    assertContains('Sito in lavorazione', renderPage(['kris_wip_preview' => '1'], $root, 'index.php', true)['output'], 'l admin vede la pagina con kris_wip_preview');
    assertSame(503, renderPage([], $root, 'sitemap.php')['status']);

    file_put_contents("{$root}/template/kris_wip.html", '<!doctype html><html><head></head><body><h1>TEMA: {{title}}</h1></body></html>');
    assertContains('TEMA: Sito in lavorazione', renderPage([], $root)['output'], 'il template del tema, se c è');

    // Spento: i visitatori vedono il sito, chi ha l editor aperto può vedere la pagina in anteprima
    $data = json_decode((string) file_get_contents("{$root}/data/k_data.json"), true);
    foreach ($data as &$entity) {
        if ($entity['name'] !== 'kris_wip') continue;
        foreach ($entity['data'] as &$field) if ($field['name'] === 'enabled') $field['value'] = '';
    }
    unset($entity, $field);
    file_put_contents("{$root}/data/k_data.json", json_encode($data));
    assertSame(200, renderPage([], $root)['status']);
    assertNotContains('Lavori in corso attivi', renderPage([], $root, 'index.php', true)['output'], 'spento: niente striscia');
    assertContains('TEMA: Sito in lavorazione', renderPage(['kris_wip_preview' => '1'], $root, 'index.php', true)['output'], 'anteprima anche spenta');
});

test('proprieta del sito e campi seo_* della pagina: titolo, description, Open Graph, verifiche', function () {
    $root = pageSandboxCopy();
    $migration = require KRIS_ROOT . '/kris/migrations/0008_proprieta_del_sito.php';
    $files = $migration['up'](['k_data' => json_decode((string) file_get_contents("{$root}/data/k_data.json"), true), 'k_model' => json_decode((string) file_get_contents("{$root}/data/k_model.json"), true), 'cms_settings' => []]);
    assertSame($files, $migration['up']($files), 'idempotente');
    $set = function (array &$data, string $name, string $field, mixed $value): void {
        foreach ($data as &$e) {
            if ($e['name'] !== $name || (int) $e['id'] !== 0) continue;
            foreach ($e['data'] as &$f) if ($f['name'] === $field) { $f['value'] = $value; return; }
            $e['data'][] = ['name' => $field, 'type' => is_array($value) ? 'text' : 'plain', 'value' => $value];
            return;
        }
    };
    $set($files['k_data'], 'kris_site', 'site_name', ['it' => 'Bar Centrale', 'en' => 'Bar Centrale']);
    $set($files['k_data'], 'kris_site', 'title_template', '{title} | {site}');
    $set($files['k_data'], 'kris_site', 'description', ['it' => 'Il bar del paese', 'en' => 'The village bar']);
    $set($files['k_data'], 'kris_site', 'share_image', ['it' => 'assets/uploads/condivisione.jpg', 'en' => 'assets/uploads/condivisione.jpg']);
    $set($files['k_data'], 'kris_site', 'google_verification', 'abc123');
    file_put_contents("{$root}/data/k_data.json", json_encode($files['k_data']));
    file_put_contents("{$root}/data/k_model.json", json_encode($files['k_model']));

    $out = renderPage([], $root)['output'];
    assertContains('<title>Kris 2 - CMS leggero per HTML statico | Bar Centrale</title>', $out);
    assertContains('<meta property="og:title" content="Kris 2 - CMS leggero per HTML statico | Bar Centrale">', $out);
    assertContains('<meta property="og:image" content="http://localhost/assets/uploads/condivisione.jpg">', $out);
    assertContains('<meta name="google-site-verification" content="abc123">', $out);
    assertContains('<meta name="description" content="Il bar del paese">', $out);

    // Il titolo SEO della pagina vince su quello del template
    $set($files['k_data'], 'homepage', 'seo_title', ['it' => 'Colazioni a Sorrento', 'en' => 'Breakfast']);
    file_put_contents("{$root}/data/k_data.json", json_encode($files['k_data']));
    assertContains('<title>Colazioni a Sorrento | Bar Centrale</title>', renderPage([], $root)['output']);
});
