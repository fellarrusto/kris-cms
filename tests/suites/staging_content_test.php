<?php
declare(strict_types=1);

/**
 * Staging di contenuti, schema, pagine e immagini: operazioni registrate,
 * applicate all'anteprima e rieseguite sui dati del sito alla pubblicazione.
 * Ogni test lavora su un sito temporaneo con le fixture, mai su data/.
 */

use Kris\Staging\Staging;
use Kris\Staging\StagingException;

// PNG 1x1 valido: getimagesize() lo riconosce anche senza GD
const KRIS_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** Sito temporaneo con le fixture dei contenuti, i template della repo e due lingue. */
function contentSite(): string
{
    $root = sys_get_temp_dir() . '/kris-staging-c-' . bin2hex(random_bytes(4));
    mkdir("{$root}/data", 0777, true);
    mkdir("{$root}/config", 0777, true);
    mkdir("{$root}/assets/uploads", 0777, true);
    rcopy(KRIS_ROOT . '/template', "{$root}/template");
    copy(KRIS_TESTS . '/fixtures/k_data.json', "{$root}/data/k_data.json");
    copy(KRIS_TESTS . '/fixtures/k_model.json', "{$root}/data/k_model.json");
    file_put_contents("{$root}/data/cms_settings.json", json_encode(['languages' => ['it', 'en']]));
    copy(KRIS_ROOT . '/config/allowed_pages.json', "{$root}/config/allowed_pages.json");
    register_shutdown_function(fn() => rrmdir($root));
    return $root;
}

/** Valore di un campo di un'entita radice nei dati del sito (o dell'anteprima). */
function siteValue(string $file, string $key, int $id, string $field): mixed
{
    foreach (json_decode((string) file_get_contents($file), true) as $entity) {
        if ($entity['name'] === $key && (int) $entity['id'] === $id) {
            foreach ($entity['data'] as $f) {
                if ($f['name'] === $field) return $f['value'];
            }
        }
    }
    return null;
}

function arrayIds(string $file, string $field): array
{
    return array_map(fn($c) => $c['id'], siteValue($file, 'homepage', 0, $field) ?? []);
}

function expectStaging(callable $fn, string $contains): void
{
    try {
        $fn();
    } catch (StagingException $e) {
        assertContains($contains, $e->getMessage() . ' ' . implode(' ', $e->details()));
        return;
    }
    fail('era attesa una StagingException');
}

test('set_field cambia l anteprima e lascia stare il sito', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => 'Titolo nuovo'], 'agente');

    $preview = siteValue("{$root}/data/staging/data/k_data.json", 'homepage', 0, 'page_title');
    assertSame('Titolo nuovo', $preview['it']);
    assertTrue($preview['en'] !== '', 'le altre lingue restano');
    assertTrue(siteValue("{$root}/data/k_data.json", 'homepage', 0, 'page_title')['it'] !== 'Titolo nuovo');
});

test('le operazioni non valide vengono rifiutate prima di essere registrate', function () {
    $s = new Staging(contentSite());
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'inesistente', 'value' => 'x'], 'a'), 'non esiste');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'de', 'value' => 'x'], 'a'), 'non è attiva');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => '<b>x</b>'], 'a'), 'niente HTML');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'logo', 'lang' => 'it', 'value' => 'assets/uploads/manca.png'], 'a'), 'non esiste');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'steps', 'value' => []], 'a'), 'elenco');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'kris_legal', 'id' => 0, 'field' => 'owner_name', 'value' => 'x'], 'a'), 'appartiene a Kris');
    expectStaging(fn() => $s->operate(['op' => 'boh'], 'a'), 'sconosciuta');
    assertSame([], $s->operations());
});

test('add_item, move_item e remove_item lavorano sugli elenchi senza rinumerare', function () {
    $root = contentSite();
    $s = new Staging($root);
    $added = $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps', 'position' => 'start',
        'fields' => ['title' => ['it' => 'Primo passo', 'en' => 'First step']]], 'agente');
    assertSame(3, $added['assigned_id']);
    $preview = "{$root}/data/staging/data/k_data.json";
    assertSame([3, 0, 1, 2], arrayIds($preview, 'steps'));

    $s->operate(['op' => 'move_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps', 'item_id' => 3, 'to' => 3], 'agente');
    assertSame([0, 1, 2, 3], arrayIds($preview, 'steps'));
    $s->operate(['op' => 'remove_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps', 'item_id' => 1], 'agente');
    assertSame([0, 2, 3], arrayIds($preview, 'steps'));

    $new = siteValue($preview, 'homepage', 0, 'steps')[2]['data'];
    assertSame('Primo passo', $new[0]['value']['it']);
    assertSame(['it' => '', 'en' => ''], $new[1]['value'], 'i campi non indicati nascono vuoti');
});

test('i campi annidati si raggiungono con il percorso', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 2], 'field' => 'title', 'value' => ['it' => 'Terzo', 'en' => 'Third']], 'a');
    $step = siteValue("{$root}/data/staging/data/k_data.json", 'homepage', 0, 'steps')[2];
    assertSame(2, $step['id']);
    assertSame(['it' => 'Terzo', 'en' => 'Third'], $step['data'][0]['value']);
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 9], 'field' => 'title', 'value' => 'x'], 'a'), 'non esiste');
});

test('schema: aggiungere, rinominare e togliere un campo aggiorna anche i contenuti', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'claim', 'type' => 'text', 'description' => 'Frase sotto il titolo']], 'a');
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'path' => ['steps'], 'field' => ['name' => 'icona', 'type' => 'plain']], 'a');
    $s->operate(['op' => 'rename_field', 'collection' => 'homepage', 'from' => 'btn_docs', 'to' => 'btn_documentazione'], 'a');
    $s->operate(['op' => 'remove_field', 'collection' => 'homepage', 'name' => 'btn_download'], 'a');

    $model = json_decode((string) file_get_contents("{$root}/data/staging/data/k_model.json"), true)['homepage'];
    $names = array_column($model, 'name');
    assertTrue(in_array('claim', $names, true) && in_array('btn_documentazione', $names, true));
    assertTrue(!in_array('btn_docs', $names, true) && !in_array('btn_download', $names, true));
    $data = "{$root}/data/staging/data/k_data.json";
    assertSame(['it' => '', 'en' => ''], siteValue($data, 'homepage', 0, 'claim'));
    assertSame(null, siteValue($data, 'homepage', 0, 'btn_docs'));
    assertTrue(siteValue($data, 'homepage', 0, 'btn_documentazione') !== null, 'il valore segue il nuovo nome');
    assertSame('icona', array_slice(siteValue($data, 'homepage', 0, 'steps')[0]['data'], -1)[0]['name']);
});

test('schema: nomi riservati, tipi inventati e raccolte di Kris vengono rifiutati', function () {
    $s = new Staging(contentSite());
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'id', 'type' => 'text']], 'a'), 'riservato');
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'data', 'type' => 'date']], 'a'), 'Tipo di campo non valido');
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'Titolo', 'type' => 'text']], 'a'), 'Nome non valido');
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'kris_legal', 'field' => ['name' => 'extra', 'type' => 'text']], 'a'), 'appartiene a Kris');
    expectStaging(fn() => $s->operate(['op' => 'create_collection', 'name' => 'kris_altro', 'fields' => []], 'a'), 'appartiene a Kris');
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'page_title', 'type' => 'text']], 'a'), 'esiste già');
});

test('raccolta nuova, contenuto nuovo e pagina nuova', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'create_collection', 'name' => 'progetti', 'fields' => [
        ['name' => 'titolo', 'type' => 'text'], ['name' => 'anno', 'type' => 'plain'],
    ]], 'a');
    $created = $s->operate(['op' => 'create_entity', 'key' => 'progetti', 'fields' => ['titolo' => 'Villa al mare', 'anno' => 2026]], 'a');
    assertSame(0, $created['assigned_id']);
    expectStaging(fn() => $s->operate(['op' => 'register_page', 'page' => 'progetto'], 'a'), 'serve prima il template');
    $s->write('template/progetto.html', '<!doctype html><html><body><h1>{{titolo}}</h1></body></html>', null, 'a');
    $s->operate(['op' => 'register_page', 'page' => 'progetto', 'sitemap' => ['key' => 'progetti']], 'a');

    $data = "{$root}/data/staging/data/k_data.json";
    assertSame(['it' => 'Villa al mare', 'en' => ''], siteValue($data, 'progetti', 0, 'titolo'));
    assertSame('2026', siteValue($data, 'progetti', 0, 'anno'));
    $pages = json_decode((string) file_get_contents("{$root}/data/staging/config/allowed_pages.json"), true)['allowed_pages'];
    assertTrue(in_array('progetto', $pages, true));
    $seo = json_decode((string) file_get_contents("{$root}/data/staging/config/seo.json"), true);
    assertTrue(in_array(['page' => 'progetto', 'key' => 'progetti'], $seo['sitemap'], true));
});

test('un immagine caricata si usa nei campi image e passa gli stessi controlli dell editor', function () {
    $root = contentSite();
    $s = new Staging($root);
    $tmp = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($tmp, base64_decode(KRIS_TEST_PNG));
    $url = $s->upload($tmp, 'Logo Nuovo.png', 'agente');
    assertTrue(preg_match('#^assets/uploads/logo-nuovo-[0-9a-f]{8}\.png$#', $url) === 1, $url);
    assertTrue(is_file("{$root}/data/staging/uploads/" . basename($url)));
    assertTrue(!is_file("{$root}/{$url}"), 'non e ancora pubblica');
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'logo', 'lang' => 'it', 'value' => $url], 'agente');

    $fake = tempnam(sys_get_temp_dir(), 'php');
    file_put_contents($fake, '<?php echo 1;');
    expectStaging(fn() => $s->upload($fake, 'immagine.png', 'agente'), 'non corrisponde');
    expectStaging(fn() => $s->upload($fake, 'script.php', 'agente'), 'Formato non ammesso');
    @unlink($tmp);
    @unlink($fake);
});

test('la pubblicazione riapplica le operazioni ai dati attuali e non perde le modifiche del cliente', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => 'Dall agente'], 'agente');
    $tmp = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($tmp, base64_decode(KRIS_TEST_PNG));
    $url = $s->upload($tmp, 'foto.png', 'agente');
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'logo', 'lang' => 'it', 'value' => $url], 'agente');

    // Intanto il cliente cambia un altro campo dall'editor
    $data = json_decode((string) file_get_contents("{$root}/data/k_data.json"), true);
    foreach ($data[0]['data'] as &$f) if ($f['name'] === 'page_subtitle') $f['value']['it'] = 'Dal cliente';
    unset($f);
    file_put_contents("{$root}/data/k_data.json", json_encode($data));

    $s->promote('admin');

    assertSame('Dall agente', siteValue("{$root}/data/k_data.json", 'homepage', 0, 'page_title')['it']);
    assertSame('Dal cliente', siteValue("{$root}/data/k_data.json", 'homepage', 0, 'page_subtitle')['it']);
    assertSame($url, siteValue("{$root}/data/k_data.json", 'homepage', 0, 'logo')['it']);
    assertTrue(is_file("{$root}/{$url}"), 'l immagine e stata pubblicata');
    assertSame(false, $s->exists());

    $s->undoLastPromotion();
    assertTrue(siteValue("{$root}/data/k_data.json", 'homepage', 0, 'page_title')['it'] !== 'Dall agente');
    assertTrue(!is_file("{$root}/{$url}"), 'l annullamento toglie l immagine');
    @unlink($tmp);
});

test('lo stesso campo cambiato sul sito nel frattempo e un conflitto', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => 'Dall agente'], 'agente');
    $data = json_decode((string) file_get_contents("{$root}/data/k_data.json"), true);
    foreach ($data[0]['data'] as &$f) if ($f['name'] === 'page_title') $f['value']['it'] = 'Dal cliente';
    unset($f);
    file_put_contents("{$root}/data/k_data.json", json_encode($data));

    $report = $s->rebuild();
    assertContains('cambiato sul sito', (string) $report[0]['conflict']);
    expectStaging(fn() => $s->promote('admin'), 'cambiato sul sito');
    assertSame('Dal cliente', siteValue("{$root}/data/k_data.json", 'homepage', 0, 'page_title')['it']);

    $s->promote('admin', true);
    assertSame('Dall agente', siteValue("{$root}/data/k_data.json", 'homepage', 0, 'page_title')['it']);
});

test('un campo tolto ma ancora usato da un template blocca la pubblicazione', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'remove_field', 'collection' => 'homepage', 'name' => 'page_title'], 'agente');
    assertTrue((bool) array_filter($s->templateProblems(), fn($p) => str_contains($p, 'template/homepage.html usa ancora «page_title»')));
    expectStaging(fn() => $s->promote('admin'), 'page_title');

    // Aggiornato il template nello staging, resta solo l'altro template che lo usa
    $read = $s->read('template/homepage.html');
    $s->write('template/homepage.html', str_replace('{{page_title}}', 'Kris', (string) $read['content']), $read['hash'], 'agente');
    assertTrue(!array_filter($s->templateProblems(), fn($p) => str_contains($p, 'template/homepage.html')));
});

test('l anteprima mostra i contenuti dello staging a un admin', function () {
    $sandbox = projectSandbox();
    $s = new Staging($sandbox);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => 'TITOLO-STAGING'], 'agente');
    try {
        assertContains('TITOLO-STAGING', renderPage(['kris_preview' => '1'], null, 'index.php', true)['output']);
        assertNotContains('TITOLO-STAGING', renderPage(['kris_preview' => '1'])['output']);
        assertNotContains('TITOLO-STAGING', renderPage([], null, 'index.php', true)['output']);
    } finally {
        $s->discard();
    }
});
