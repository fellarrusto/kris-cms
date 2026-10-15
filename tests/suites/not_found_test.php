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
