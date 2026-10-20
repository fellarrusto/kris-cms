<?php
declare(strict_types=1);

/**
 * La pagina 404: template/404.html del tema, resa come le altre pagine, poi
 * 404.php nella root, poi quella di Kris.
 */

use Kris\Staging\Staging;

/** Copia del sito di test, separata: qui si aggiungono template e file della 404. */
function notFoundSandbox(): string
{
    $dir = sys_get_temp_dir() . '/kris-404-' . bin2hex(random_bytes(4));
    rcopy(projectSandbox(), $dir);
    register_shutdown_function(fn() => rrmdir($dir));
    return $dir;
}

const KRIS_TEST_404 = '<!doctype html><html lang="{{language}}"><head><title>Non trovata</title></head><body>'
    . '<div k-component="navbar" k-template="navbar" k-index="0"></div><h1>PAGINA-404 {{title}}</h1></body></html>';

function addNotFoundTexts(string $root): void
{
    $model = json_decode((string) file_get_contents("{$root}/data/k_model.json"), true);
    $model['not_found'] = [['name' => 'title', 'type' => 'text', 'description' => 'Titolo della pagina 404']];
    file_put_contents("{$root}/data/k_model.json", json_encode($model));
    $data = json_decode((string) file_get_contents("{$root}/data/k_data.json"), true);
    $data[] = ['name' => 'not_found', 'id' => 0, 'data' => [['name' => 'title', 'type' => 'text', 'value' => ['it' => 'Pagina sparita', 'en' => 'Not here']]]];
    file_put_contents("{$root}/data/k_data.json", json_encode($data));
}

test('senza template la 404 e quella di Kris', function () {
    $page = renderPage(['page' => 'inesistente'], notFoundSandbox());
    assertSame(404, $page['status']);
    assertContains('404', $page['output']);
});

test('template/404.html si rende con il motore: lingua, testi della raccolta not_found e componenti', function () {
    $root = notFoundSandbox();
    file_put_contents("{$root}/template/404.html", KRIS_TEST_404);
    addNotFoundTexts($root);

    $it = renderPage(['page' => 'inesistente'], $root);
    assertSame(404, $it['status']);
    assertSame(null, $it['fatal']);
    assertContains('PAGINA-404 Pagina sparita', $it['output']);
    assertContains('lang="it"', $it['output']);
    assertNotContains('k-component=', $it['output'], 'la navbar del template viene inclusa');

    $en = renderPage(['page' => 'inesistente', 'ln' => 'en'], $root);
    assertContains('PAGINA-404 Not here', $en['output']);

    // Anche un contenuto che non esiste usa la 404 del tema
    assertContains('PAGINA-404', renderPage(['page' => 'homepage', 'key' => 'homepage', 'id' => '99'], $root)['output']);
});

test('template/404.html funziona anche senza testi propri', function () {
    $root = notFoundSandbox();
    file_put_contents("{$root}/template/404.html", KRIS_TEST_404);
    $page = renderPage(['page' => 'inesistente'], $root);
    assertSame(404, $page['status']);
    assertContains('PAGINA-404', $page['output']);
});

test('la 404 del tema non si apre come pagina, nemmeno se fosse nella whitelist', function () {
    $root = notFoundSandbox();
    file_put_contents("{$root}/template/404.html", KRIS_TEST_404);
    file_put_contents("{$root}/config/allowed_pages.json", json_encode(['allowed_pages' => ['homepage', '404']]));
    assertSame(404, renderPage(['page' => '404'], $root)['status']);
});

test('404.php nella root vale ancora se il tema non ha la sua 404', function () {
    $root = notFoundSandbox();
    file_put_contents("{$root}/404.php", '<?php http_response_code(404); echo "VECCHIA-404";');
    $page = renderPage(['page' => 'inesistente'], $root);
    assertSame(404, $page['status']);
    assertContains('VECCHIA-404', $page['output']);

    // Con il template del tema vince il template
    file_put_contents("{$root}/template/404.html", KRIS_TEST_404);
    assertContains('PAGINA-404', renderPage(['page' => 'inesistente'], $root)['output']);
});

test('un template 404 rotto non diventa un errore: si usa la 404 di riserva', function () {
    $root = notFoundSandbox();
    file_put_contents("{$root}/template/404.html", '<div k-component="navbar" k-template="manca" k-index="0"></div>');
    $page = renderPage(['page' => 'inesistente'], $root);
    assertSame(404, $page['status']);
    assertSame(null, $page['fatal']);
});

test('la 404 preparata nello staging si vede in anteprima, con la barra, e non sul sito', function () {
    $root = notFoundSandbox();
    (new Staging($root))->write('template/404.html', KRIS_TEST_404, null, 'agente');

    $preview = renderPage(['page' => 'inesistente', 'kris_preview' => '1'], $root, 'index.php', true);
    assertSame(404, $preview['status']);
    assertContains('PAGINA-404', $preview['output']);
    assertContains('id="kris-preview-bar"', $preview['output']);

    assertNotContains('PAGINA-404', renderPage(['page' => 'inesistente'], $root)['output']);
});

// --- sezione Pagina 404 (kris_notfound) ---------------------------------------

function addNotFoundSection(string $root, array $override = []): void
{
    $files = ['k_data' => json_decode((string) file_get_contents("{$root}/data/k_data.json"), true),
        'k_model' => json_decode((string) file_get_contents("{$root}/data/k_model.json"), true), 'cms_settings' => ['languages' => ['it', 'en']]];
    $files = ((require KRIS_ROOT . '/kris/migrations/0006_pagina_404.php')['up'])($files);
    foreach ($files['k_data'] as &$entity) {
        if ($entity['name'] !== 'kris_notfound') continue;
        foreach ($entity['data'] as &$field) {
            if (isset($override[$field['name']])) $field['value'] = $override[$field['name']];
        }
        unset($field);
    }
    unset($entity);
    file_put_contents("{$root}/data/k_data.json", json_encode($files['k_data']));
    file_put_contents("{$root}/data/k_model.json", json_encode($files['k_model']));
}

test('0006 crea la sezione Pagina 404 con testi predefiniti, e non tocca quelli modificati', function () {
    $m = require KRIS_ROOT . '/kris/migrations/0006_pagina_404.php';
    $once = ($m['up'])(['k_data' => [], 'k_model' => [], 'cms_settings' => ['languages' => ['it', 'en']]]);
    assertSame(['title', 'text', 'button_label', 'button_url', 'image'], array_column($once['k_model']['kris_notfound'], 'name'));
    $values = array_column($once['k_data'][0]['data'], 'value', 'name');
    assertSame('Pagina non trovata', $values['title']['it']);
    assertSame('index.php', $values['button_url']);
    $once['k_data'][0]['data'][0]['value']['it'] = 'Ops';
    $twice = ($m['up'])($once);
    assertSame(1, count($twice['k_data']));
    assertSame('Ops', $twice['k_data'][0]['data'][0]['value']['it']);
});

test('la 404 di serie usa i testi della sezione Pagina 404, nella lingua richiesta', function () {
    $root = notFoundSandbox();
    addNotFoundSection($root, ['title' => ['it' => 'Qui non c\'è niente', 'en' => 'Nothing here'],
        'button_url' => 'index.php?page=homepage&ln=it']);
    $it = renderPage(['page' => 'inesistente'], $root);
    assertSame(404, $it['status']);
    assertContains('Qui non c&#039;è niente', $it['output']);
    assertContains('Torna alla home', $it['output']);
    assertContains('href="index.php?page=homepage&amp;ln=it"', $it['output']);
    assertContains('Nothing here', renderPage(['page' => 'inesistente', 'ln' => 'en'], $root)['output']);
});

test('il template 404.html del tema riceve i campi della sezione Pagina 404', function () {
    $root = notFoundSandbox();
    addNotFoundSection($root);
    file_put_contents("{$root}/template/404.html", '<!doctype html><html><head><title>x</title></head><body><h1>{{title}}</h1><p>{{text}}</p></body></html>');
    $page = renderPage(['page' => 'inesistente'], $root);
    assertSame(404, $page['status']);
    assertContains('<h1>Pagina non trovata</h1>', $page['output']);
});

test('una regola del tema (not_found) vince sulla sezione di Kris', function () {
    $root = notFoundSandbox();
    addNotFoundSection($root);
    addNotFoundTexts($root);
    file_put_contents("{$root}/template/404.html", '<!doctype html><html><body><h1>{{title}}</h1></body></html>');
    assertContains('<h1>Pagina sparita</h1>', renderPage(['page' => 'inesistente'], $root)['output']);
});

test('l aggiornamento crea il .htaccess con la regola 404 solo se non c e gia', function () {
    $root = sys_get_temp_dir() . '/kris-htaccess-' . bin2hex(random_bytes(3));
    mkdir($root);
    register_shutdown_function(fn() => rrmdir($root));
    assertTrue(in_array('.htaccess', \Kris\Update\Stubs::ensure($root), true));
    $rules = (string) file_get_contents("{$root}/.htaccess");
    assertContains('index.php?page=404 [L]', $rules);
    assertNotContains('R=301', $rules);
    assertNotContains('R=302', $rules);
    assertContains('RewriteRule ^assets/ - [R=404,L]', $rules);
    file_put_contents("{$root}/.htaccess", "# del cliente\n");
    assertTrue(!in_array('.htaccess', \Kris\Update\Stubs::ensure($root), true));
    assertSame("# del cliente\n", file_get_contents("{$root}/.htaccess"));
});
