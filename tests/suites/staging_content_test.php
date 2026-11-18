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

/**
 * Sito temporaneo nel formato 2 con le fixture dei contenuti, i template della
 * repo e due lingue. Gli elenchi della homepage restano elenchi, tranne posts
 * (in Posts nelle fixture), che diventa un tipo.
 */
function contentSite(?callable $prepare = null): string
{
    $root = sys_get_temp_dir() . '/kris-staging-c-' . bin2hex(random_bytes(4));
    mkdir("{$root}/config", 0777, true);
    mkdir("{$root}/assets/uploads", 0777, true);
    rcopy(KRIS_ROOT . '/template', "{$root}/template");
    writeFormat2($root, format2Fixture($prepare));
    register_shutdown_function(fn() => rrmdir($root));
    return $root;
}

/** Valore di un campo di un contenuto nel documento content del sito (o dell'anteprima). */
function siteValue(string $file, string $key, int $id, string $field): mixed
{
    foreach (json_decode((string) file_get_contents($file), true) as $item) {
        if ($item['type'] === $key && (int) $item['id'] === $id) return $item['values'][$field] ?? null;
    }
    return null;
}

/** Lo schema del sito o dell'anteprima. */
function schemaOf(string $file): array
{
    return json_decode((string) file_get_contents($file), true);
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

    $preview = siteValue("{$root}/data/staging/data/content.json", 'homepage', 0, 'page_title');
    assertSame('Titolo nuovo', $preview['it']);
    assertTrue($preview['en'] !== '', 'le altre lingue restano');
    assertTrue(siteValue("{$root}/data/content.json", 'homepage', 0, 'page_title')['it'] !== 'Titolo nuovo');
});

test('le operazioni non valide vengono rifiutate prima di essere registrate', function () {
    $s = new Staging(contentSite());
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'inesistente', 'value' => 'x'], 'a'), 'non esiste');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'de', 'value' => 'x'], 'a'), 'non è attiva');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => '<b>x</b>'], 'a'), 'niente HTML');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'logo', 'lang' => 'it', 'value' => 'assets/uploads/manca.png'], 'a'), 'non esiste');
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'steps', 'value' => []], 'a'), 'elenco');
    expectStaging(fn() => $s->operate(['op' => 'create_collection', 'name' => 'kris_nuova', 'fields' => []], 'a'), 'appartiene a Kris');
    expectStaging(fn() => $s->operate(['op' => 'boh'], 'a'), 'sconosciuta');
    assertSame([], $s->operations());
});

test('add_item, move_item e remove_item lavorano sugli elenchi senza rinumerare', function () {
    $root = contentSite();
    $s = new Staging($root);
    $added = $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps', 'position' => 'start',
        'fields' => ['title' => ['it' => 'Primo passo', 'en' => 'First step']]], 'agente');
    assertSame(3, $added['assigned_id']);
    $preview = "{$root}/data/staging/data/content.json";
    assertSame([3, 0, 1, 2], arrayIds($preview, 'steps'));

    $s->operate(['op' => 'move_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps', 'item_id' => 3, 'to' => 3], 'agente');
    assertSame([0, 1, 2, 3], arrayIds($preview, 'steps'));
    $s->operate(['op' => 'remove_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps', 'item_id' => 1], 'agente');
    assertSame([0, 2, 3], arrayIds($preview, 'steps'));

    $new = siteValue($preview, 'homepage', 0, 'steps')[2]['values'];
    assertSame('Primo passo', $new['title']['it']);
    assertSame(['it' => '', 'en' => ''], $new['description'], 'i campi non indicati nascono vuoti');
});

test('i campi annidati si raggiungono con il percorso', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 2], 'field' => 'title', 'value' => ['it' => 'Terzo', 'en' => 'Third']], 'a');
    $step = siteValue("{$root}/data/staging/data/content.json", 'homepage', 0, 'steps')[2];
    assertSame(2, $step['id']);
    assertSame(['it' => 'Terzo', 'en' => 'Third'], $step['values']['title']);
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 9], 'field' => 'title', 'value' => 'x'], 'a'), 'non esiste');
});

test('schema: aggiungere, rinominare e togliere un campo aggiorna anche i contenuti', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'claim', 'type' => 'text', 'description' => 'Frase sotto il titolo']], 'a');
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'path' => ['steps'], 'field' => ['name' => 'icona', 'type' => 'plain']], 'a');
    $s->operate(['op' => 'rename_field', 'collection' => 'homepage', 'from' => 'btn_docs', 'to' => 'btn_documentazione'], 'a');
    $s->operate(['op' => 'remove_field', 'collection' => 'homepage', 'name' => 'btn_download'], 'a');

    $fields = schemaOf("{$root}/data/staging/data/schema.json")['types']['homepage']['fields'];
    $names = array_column($fields, 'name');
    assertTrue(in_array('claim', $names, true) && in_array('btn_documentazione', $names, true));
    assertTrue(!in_array('btn_docs', $names, true) && !in_array('btn_download', $names, true));
    assertSame(['name' => 'claim', 'type' => 'text', 'localized' => true, 'description' => 'Frase sotto il titolo'], end($fields));
    $data = "{$root}/data/staging/data/content.json";
    assertSame(['it' => '', 'en' => ''], siteValue($data, 'homepage', 0, 'claim'));
    assertSame(null, siteValue($data, 'homepage', 0, 'btn_docs'));
    assertTrue(siteValue($data, 'homepage', 0, 'btn_documentazione') !== null, 'il valore segue il nuovo nome');
    $step = siteValue($data, 'homepage', 0, 'steps')[0]['values'];
    assertSame('icona', array_key_last($step));
    assertSame('', $step['icona'], 'plain è un testo condiviso');
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

    $data = "{$root}/data/staging/data/content.json";
    assertSame(['it' => 'Villa al mare', 'en' => ''], siteValue($data, 'progetti', 0, 'titolo'));
    assertSame('2026', siteValue($data, 'progetti', 0, 'anno'));
    $pages = schemaOf("{$root}/data/staging/data/schema.json")['pages'];
    assertSame(['template' => 'progetto', 'type' => 'progetti', 'sitemap' => true], $pages['progetto']);
});

test('un richtext con script dell agente aspetta l approvazione come un file', function () {
    $root = contentSite();
    $s = new Staging($root);
    // Testo formattato normale: nessuna approvazione
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'hero_description', 'lang' => 'it', 'value' => '<p>Ciao <a href="https://esempio.it">qui</a></p>'], 'agente');
    assertSame([], $s->codeFiles());
    assertTrue($s->codeApproved());

    // Un gestore di eventi in un richtext gira nell'anteprima con la sessione dell'editor
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'hero_description', 'lang' => 'en', 'value' => '<img src="x" onerror="fetch(\'/editor/\')">'], 'agente');
    $files = $s->codeFiles();
    assertSame(1, count($files), implode(', ', $files));
    assertContains('contenuto:', $files[0]);
    assertSame(false, $s->codeApproved());
    $s->approveCode((string) $s->codeFingerprint(), 'admin');
    assertTrue($s->codeApproved());

    // Cambia il testo: l'approvazione non vale più
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'hero_description', 'lang' => 'en', 'value' => '<script>alert(1)</script>'], 'agente');
    assertSame(false, $s->codeApproved());

    // Anche dentro un elemento nuovo di un elenco, in un campo richtext
    $s->discard();
    $s->operate(['op' => 'create_entity', 'key' => 'posts', 'fields' => ['body' => ['it' => '<a href="javascript:alert(1)">x</a>']]], 'agente');
    assertSame(false, $s->codeApproved());
    // Un campo plain con lo stesso testo esce con l'escape: non è codice
    $s->discard();
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'code_example', 'value' => '<script>x</script>'], 'agente');
    assertTrue($s->codeApproved());
});

test('una pagina registrata con sitemap false resta fuori dalla sitemap', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->write('template/lavagna.html', '<!doctype html><html><body>{{page_title}}</body></html>', null, 'a');
    $s->operate(['op' => 'register_page', 'page' => 'lavagna', 'sitemap' => false], 'a');

    $pages = schemaOf("{$root}/data/staging/data/schema.json")['pages'];
    assertSame(['template' => 'lavagna', 'type' => 'homepage', 'item' => 0, 'sitemap' => false], $pages['lavagna'], 'la pagina e pubblica, fuori dalla sitemap');
    assertContains('fuori dalla sitemap', $s->rebuild()[0]['description']);
});

test('set_sitemap mette e toglie dalla sitemap una pagina gia pubblica', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->write('template/progetto.html', '<!doctype html><html><body>{{page_title}}</body></html>', null, 'a');
    $s->operate(['op' => 'register_page', 'page' => 'progetto', 'sitemap' => ['key' => 'homepage']], 'a');
    $page = fn() => schemaOf("{$root}/data/staging/data/schema.json")['pages']['progetto'];
    assertSame(['template' => 'progetto', 'type' => 'homepage', 'item' => 0, 'sitemap' => true], $page());

    $s->operate(['op' => 'set_sitemap', 'page' => 'progetto', 'sitemap' => false], 'a');
    assertSame(false, $page()['sitemap']);
    $s->operate(['op' => 'set_sitemap', 'page' => 'progetto', 'sitemap' => ['key' => 'navbar', 'id' => 0]], 'a');
    assertSame(['template' => 'progetto', 'type' => 'navbar', 'item' => 0, 'sitemap' => true], $page());
    expectStaging(fn() => $s->operate(['op' => 'set_sitemap', 'page' => 'inesistente', 'sitemap' => false], 'a'), 'non è una pagina pubblica');
    expectStaging(fn() => $s->operate(['op' => 'set_sitemap', 'page' => 'progetto'], 'a'), 'vuole «sitemap»');
    expectStaging(fn() => $s->operate(['op' => 'set_sitemap', 'page' => 'progetto', 'sitemap' => ['chiave' => 'x']], 'a'), 'non conosce');
    expectStaging(fn() => $s->operate(['op' => 'register_page', 'page' => 'progetto', 'mappa' => false], 'a'), 'non conosce');

    // Alla pubblicazione la regola arriva al sito
    $s->promote('admin');
    assertSame('navbar', schemaOf("{$root}/data/schema.json")['pages']['progetto']['type']);
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
    $data = json_decode((string) file_get_contents("{$root}/data/content.json"), true);
    $data[0]['values']['page_subtitle']['it'] = 'Dal cliente';
    file_put_contents("{$root}/data/content.json", json_encode($data));

    $s->promote('admin');

    assertSame('Dall agente', siteValue("{$root}/data/content.json", 'homepage', 0, 'page_title')['it']);
    assertSame('Dal cliente', siteValue("{$root}/data/content.json", 'homepage', 0, 'page_subtitle')['it']);
    assertSame($url, siteValue("{$root}/data/content.json", 'homepage', 0, 'logo')['it']);
    assertTrue(is_file("{$root}/{$url}"), 'l immagine e stata pubblicata');
    assertSame(false, $s->exists());

    $s->undoLastPromotion();
    assertTrue(siteValue("{$root}/data/content.json", 'homepage', 0, 'page_title')['it'] !== 'Dall agente');
    assertTrue(!is_file("{$root}/{$url}"), 'l annullamento toglie l immagine');
    @unlink($tmp);
});

test('lo stesso campo cambiato sul sito nel frattempo e un conflitto', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => 'Dall agente'], 'agente');
    $data = json_decode((string) file_get_contents("{$root}/data/content.json"), true);
    $data[0]['values']['page_title']['it'] = 'Dal cliente';
    file_put_contents("{$root}/data/content.json", json_encode($data));

    $report = $s->rebuild();
    assertContains('cambiato sul sito', (string) $report[0]['conflict']);
    expectStaging(fn() => $s->promote('admin'), 'cambiato sul sito');
    assertSame('Dal cliente', siteValue("{$root}/data/content.json", 'homepage', 0, 'page_title')['it']);

    $s->promote('admin', true);
    assertSame('Dall agente', siteValue("{$root}/data/content.json", 'homepage', 0, 'page_title')['it']);
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

test('un campo omonimo di un altra raccolta non blocca la pubblicazione', function () {
    $root = contentSite();
    $s = new Staging($root);
    // homepage e navbar hanno entrambe page_title: si toglie solo quello della navbar
    $s->operate(['op' => 'remove_field', 'collection' => 'navbar', 'name' => 'page_title'], 'agente');
    $problems = $s->templateProblems();
    assertTrue((bool) array_filter($problems, fn($p) => str_contains($p, 'template/navbar.html usa ancora «page_title»')), implode('; ', $problems));
    assertTrue(!array_filter($problems, fn($p) => str_contains($p, 'template/homepage.html')), 'la homepage legge il suo page_title');

    // Un template senza nessun riferimento puo rendere qualunque raccolta: resta segnalato
    $s->write('template/orfano.html', '<p>{{page_title}}</p>', null, 'agente');
    assertTrue((bool) array_filter($s->templateProblems(), fn($p) => str_contains($p, 'template/orfano.html usa ancora «page_title»')));
});

test('il contesto dei template si ricava da pagine, link, componenti ed elenchi', function () {
    $schema = ['types' => [
        'homepage' => ['fields' => [['name' => 'features', 'type' => 'list', 'of' => [
            ['name' => 'title', 'type' => 'text', 'localized' => true],
            ['name' => 'highlights', 'type' => 'list', 'of' => [['name' => 'title', 'type' => 'text', 'localized' => true]]],
        ]]]],
        'torte' => ['fields' => [['name' => 'message', 'type' => 'text', 'localized' => true]]],
        'navbar' => ['fields' => [['name' => 'logo', 'type' => 'media', 'localized' => true]]],
        'progetti' => ['fields' => [['name' => 'title', 'type' => 'text', 'localized' => true]]],
        'posts' => ['fields' => [['name' => 'title', 'type' => 'text', 'localized' => true]]],
    ], 'pages' => ['lavagna' => ['template' => 'lavagna', 'type' => 'lavagna', 'sitemap' => true]],
       'promoted' => [['from' => 'homepage', 'id' => 0, 'list' => 'posts', 'to' => 'posts']]];
    $templates = [
        'homepage' => '<header k-component="navbar" k-template="navbar" k-index="0"></header><div k-array="features" k-template="card"></div><div k-array="progetti" k-template="progetto-card"></div>',
        'card' => '<a href="?page=detail&amp;key=homepage&amp;id=0&amp;path=features/{{id}}">{{title}}</a>',
        'detail' => '<ul k-array="highlights" k-template="highlight"></ul>',
        'highlight' => '<li>{{title}}</li>',
        'navbar' => '<a href="index.php?page=torte&amp;key=torte&amp;ln={{language}}">{{logo}}</a>',
        'torte' => '<p>{{message}}</p>',
        'progetto-card' => '<h3>{{title}}</h3>',
        'lavagna' => '<p>{{message}}</p>',
        'post-card' => '<a href="?page=post&amp;id=0&amp;path=posts/{{id}}">{{title}}</a>',
        'post' => '<h1>{{title}}</h1>',
    ];
    $ctx = \Kris\Staging\TemplateUsage::contexts($templates, [$schema]);
    assertSame(['homepage'], $ctx['homepage']);
    assertSame(['navbar'], $ctx['navbar']);
    assertSame(['homepage/features'], $ctx['card']);
    assertSame(['homepage/features'], $ctx['detail']);
    assertSame(['homepage/features/highlights'], $ctx['highlight']);
    assertSame(['torte'], $ctx['torte']);
    assertSame(['progetti'], $ctx['progetto-card'], 'k-array senza elenco omonimo: la raccolta');
    assertSame(['lavagna'], $ctx['lavagna']);
    assertSame(['posts'], $ctx['post'], 'un vecchio link a un elenco promosso vale il tipo nuovo');
    assertSame(null, \Kris\Staging\TemplateUsage::contexts(['solo' => '<p>{{x}}</p>'], [$schema])['solo']);
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

test('la favicon (kris_site) si prepara con set_field; il resto delle raccolte kris_ resta chiuso', function () {
    $root = contentSite(fn(array $files): array => ((require KRIS_ROOT . '/kris/migrations/0004_favicon.php')['up'])($files));

    $s = new Staging($root);
    $tmp = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($tmp, base64_decode(KRIS_TEST_PNG));
    $url = $s->upload($tmp, 'icona.png', 'agente');
    $s->operate(['op' => 'set_field', 'key' => 'kris_site', 'id' => 0, 'field' => 'favicon', 'lang' => 'it', 'value' => $url], 'agente');
    expectStaging(fn() => $s->operate(['op' => 'set_hidden', 'key' => 'kris_site', 'id' => 0, 'hidden' => true], 'agente'), 'non esiste');
    expectStaging(fn() => $s->operate(['op' => 'create_entity', 'key' => 'kris_site'], 'agente'), 'appartiene a Kris');
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'kris_site', 'field' => ['name' => 'extra', 'type' => 'text']], 'agente'), 'appartiene a Kris');

    $s->promote('admin');
    assertSame($url, siteValue("{$root}/data/content.json", 'kris_site', 0, 'favicon')['it']);
    assertTrue(is_file("{$root}/" . $url));
});

test('la sezione di un campo si imposta con add_field e describe_field, e vuota si toglie', function () {
    $s = new Staging(contentSite());
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'seo_note', 'type' => 'text', 'section' => ' Meta ']], 'a');
    $s->operate(['op' => 'describe_field', 'collection' => 'homepage', 'name' => 'page_title', 'section' => 'Intestazione'], 'a');
    $byName = array_column($s->state()['schema']['types']['homepage']['fields'], null, 'name');
    assertSame('Meta', $byName['seo_note']['section']);
    assertSame('Intestazione', $byName['page_title']['section']);
    // Cambiare solo la sezione non tocca la descrizione
    $description = (new Staging(contentSite()))->state()['schema']['types']['homepage']['fields'];
    assertSame(array_column($description, 'description', 'name')['page_title'] ?? null, $byName['page_title']['description'] ?? null);
    $s->operate(['op' => 'describe_field', 'collection' => 'homepage', 'name' => 'page_title', 'section' => ''], 'a');
    assertTrue(!array_key_exists('section', array_column($s->state()['schema']['types']['homepage']['fields'], null, 'name')['page_title']));
});

// --- struttura dati: raccolte, tipi, ordine, Posts, pagine ---------------------

test('remove_collection e rename_collection: schema, contenuti, Posts e pagine; i template che le usano bloccano', function () {
    $root = contentSite(function (array $files): array {
        $files['cms_settings']['post_collections'] = ['navbar'];
        $files['allowed_pages']['allowed_pages'][] = 'barra';
        $files['seo'] = ['sitemap' => [['page' => 'homepage'], ['page' => 'barra', 'key' => 'navbar', 'id' => 0]]];
        return $files;
    });
    $s = new Staging($root);
    $s->operate(['op' => 'rename_collection', 'from' => 'navbar', 'to' => 'menu'], 'a');
    $state = $s->state();
    assertTrue(isset($state['schema']['types']['menu']) && !isset($state['schema']['types']['navbar']));
    assertSame(true, $state['schema']['types']['menu']['newest_first']);
    assertSame('menu', $state['schema']['pages']['barra']['type']);
    assertTrue((bool) array_filter($state['content'], fn($e) => $e['type'] === 'menu'));
    assertTrue((bool) array_filter($s->templateProblems(), fn($p) => str_contains($p, 'usa ancora la raccolta «navbar»')));

    $s->operate(['op' => 'remove_collection', 'name' => 'menu'], 'a');
    $state = $s->state();
    assertTrue(!isset($state['schema']['types']['menu']));
    assertSame([], array_values(array_filter($state['content'], fn($e) => $e['type'] === 'menu')));
    assertTrue(!isset($state['schema']['pages']['barra']), 'la pagina che la rendeva se ne va');
    assertTrue(isset($state['schema']['pages']['homepage']));
    expectStaging(fn() => $s->operate(['op' => 'remove_collection', 'name' => 'kris_legal'], 'a'), 'appartiene a Kris');

    $s->promote('admin', true);
    $settings = json_decode((string) file_get_contents("{$root}/data/settings.json"), true);
    assertSame(['languages' => ['it', 'en']], $settings, 'le lingue restano');
});
test('change_type converte i valori; da o verso un elenco solo se vuoto', function () {
    $root = contentSite();
    $s = new Staging($root);
    $before = siteValue("{$root}/data/content.json", 'homepage', 0, 'page_title');
    $s->operate(['op' => 'change_type', 'collection' => 'homepage', 'name' => 'page_title', 'type' => 'plain'], 'a');
    $file = "{$root}/data/staging/data/content.json";
    assertSame($before['it'], siteValue($file, 'homepage', 0, 'page_title'));
    $s->operate(['op' => 'change_type', 'collection' => 'homepage', 'name' => 'page_title', 'type' => 'richtext'], 'a');
    $value = siteValue($file, 'homepage', 0, 'page_title');
    assertSame(htmlspecialchars($before['it'], ENT_QUOTES, 'UTF-8'), $value['it']);
    assertSame($value['it'], $value['en'], 'il valore plain vale per ogni lingua');
    expectStaging(fn() => $s->operate(['op' => 'change_type', 'collection' => 'homepage', 'name' => 'steps', 'type' => 'text'], 'a'), 'vuoto');
    expectStaging(fn() => $s->operate(['op' => 'change_type', 'collection' => 'homepage', 'name' => 'page_title', 'type' => 'boolean'], 'a'), 'contiene valori');
    $def = array_column($s->state()['schema']['types']['homepage']['fields'], null, 'name')['page_title'];
    assertSame(['richtext', true], [$def['type'], $def['localized']]);
    // Solo localized: lo stesso tipo, condiviso
    $s->operate(['op' => 'change_type', 'collection' => 'homepage', 'name' => 'page_title', 'type' => 'richtext', 'localized' => false], 'a');
    assertSame($value['it'], siteValue($file, 'homepage', 0, 'page_title'));
});

test('move_field, set_post_collection e unregister_page; un elenco non va più in Posts', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'move_field', 'collection' => 'homepage', 'name' => 'steps', 'to' => 0], 'a');
    assertSame('steps', $s->state()['schema']['types']['homepage']['fields'][0]['name']);
    expectStaging(fn() => $s->operate(['op' => 'set_posts', 'collection' => 'homepage', 'name' => 'steps', 'posts' => true], 'a'), 'set_post_collection');
    $s->operate(['op' => 'set_post_collection', 'collection' => 'navbar', 'enabled' => true], 'a');
    assertSame(true, $s->state()['schema']['types']['navbar']['newest_first']);
    $s->operate(['op' => 'set_post_collection', 'collection' => 'navbar', 'enabled' => false], 'a');
    assertTrue(!isset($s->state()['schema']['types']['navbar']['newest_first']));

    $pages = array_keys($s->state()['schema']['pages']);
    $page = current(array_filter($pages, fn($p) => $p !== 'homepage'));
    $s->operate(['op' => 'unregister_page', 'page' => $page], 'a');
    assertTrue(!isset($s->state()['schema']['pages'][$page]));
    expectStaging(fn() => $s->operate(['op' => 'unregister_page', 'page' => 'homepage'], 'a'), 'homepage');
});

// --- raccolte: nome visibile, descrizione, contenuto unico, ordine ----------

test('set_collection_info e move_collection; il contenuto unico protegge la pagina', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_collection_info', 'collection' => 'navbar', 'label' => ' Elementi comuni ', 'description' => 'Menu e footer', 'single' => true], 'a');
    $info = fn(string $type) => array_diff_key($s->state()['schema']['types'][$type], ['fields' => 1]);
    assertSame(['label' => 'Elementi comuni', 'description' => 'Menu e footer', 'single' => true], $info('navbar'));
    expectStaging(fn() => $s->operate(['op' => 'create_entity', 'key' => 'navbar'], 'a'), 'contenuto unico');
    expectStaging(fn() => $s->operate(['op' => 'remove_entity', 'key' => 'navbar', 'id' => 0], 'a'), 'contenuto unico');

    $s->operate(['op' => 'set_collection_info', 'collection' => 'navbar', 'description' => ''], 'a');
    assertSame(['label' => 'Elementi comuni', 'single' => true], $info('navbar'));

    $s->operate(['op' => 'move_collection', 'collection' => 'navbar', 'to' => 0], 'a');
    assertSame(['navbar', 'homepage', 'posts', 'kris_legal'], array_keys($s->state()['schema']['types']));

    $s->operate(['op' => 'rename_collection', 'from' => 'navbar', 'to' => 'comuni'], 'a');
    assertSame('Elementi comuni', $info('comuni')['label']);
    assertSame(['comuni', 'homepage', 'posts', 'kris_legal'], array_keys($s->state()['schema']['types']));
});

test('il contenuto unico si rifiuta su una raccolta con piu contenuti', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'create_entity', 'key' => 'navbar'], 'a');
    expectStaging(fn() => $s->operate(['op' => 'set_collection_info', 'collection' => 'navbar', 'single' => true], 'a'), 'contenuti');
});

test('l agente scrive tutti i valori di kris_legal (titolare, informative, banner), non il suo schema', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'kris_legal', 'id' => 0, 'field' => 'owner_vat', 'value' => '01234567890'], 'a');
    $s->operate(['op' => 'set_field', 'key' => 'kris_legal', 'id' => 0, 'field' => 'owner_name', 'value' => 'Studio Rossi snc'], 'a');
    assertSame('01234567890', siteValue("{$root}/data/staging/data/content.json", 'kris_legal', 0, 'owner_vat'));
    $s->operate(['op' => 'set_field', 'key' => 'kris_legal', 'id' => 0, 'field' => 'cookie_policy', 'lang' => 'it', 'value' => '<h2>Cookie</h2><p>Testo.</p>'], 'a');
    $s->operate(['op' => 'set_field', 'key' => 'kris_legal', 'id' => 0, 'field' => 'banner_text', 'lang' => 'it', 'value' => 'Usiamo cookie'], 'a');
    assertSame('<h2>Cookie</h2><p>Testo.</p>', siteValue("{$root}/data/staging/data/content.json", 'kris_legal', 0, 'cookie_policy')['it']);
    expectStaging(fn() => $s->operate(['op' => 'set_field', 'key' => 'kris_legal', 'id' => 0, 'field' => 'inventato', 'value' => 'x'], 'a'), 'non esiste');
    expectStaging(fn() => $s->operate(['op' => 'set_hidden', 'key' => 'kris_legal', 'id' => 0, 'hidden' => true], 'a'), 'non esiste');
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'kris_legal', 'field' => ['name' => 'extra', 'type' => 'text']], 'a'), 'appartiene a Kris');
});

test('add_item e create_entity rifiutano le chiavi che non conoscono', function () {
    $s = new Staging(contentSite());
    expectStaging(fn() => $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps',
        'data' => ['title' => ['it' => 'x']]], 'a'), '«data»');
    expectStaging(fn() => $s->operate(['op' => 'create_entity', 'key' => 'navbar', 'values' => []], 'a'), 'Chiavi ammesse');
    assertSame([], $s->operations(), 'niente elementi vuoti registrati');
});

test('add_item crea anche gli elenchi annidati, con id in ordine', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'features', 'fields' => [
        'title' => ['it' => 'Veloce', 'en' => 'Fast'],
        'highlights' => [['title' => ['it' => 'Uno', 'en' => 'One']], ['title' => ['it' => 'Due', 'en' => 'Two']]],
    ]], 'a');
    $features = siteValue("{$root}/data/staging/data/content.json", 'homepage', 0, 'features');
    $highlights = end($features)['values']['highlights'];
    assertSame([0, 1], array_column($highlights, 'id'));
    assertSame('Due', $highlights[1]['values']['title']['it']);

    expectStaging(fn() => $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'features',
        'fields' => ['highlights' => ['title' => 'x']]], 'a'), 'lista di elementi');
    expectStaging(fn() => $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'features',
        'fields' => ['highlights' => [['inventato' => 'x']]]], 'a'), 'non esiste');
});

test('un testo solo su un campo multilingua avvisa delle lingue rimaste vuote', function () {
    $s = new Staging(contentSite());
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'value' => 'Solo italiano'], 'a');
    assertSame(1, count($s->lastWarnings()));
    assertContains('lingua en', $s->lastWarnings()[0]);

    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'value' => ['it' => 'Ciao', 'en' => 'Hi']], 'a');
    assertSame([], $s->lastWarnings());
});

test('piu operazioni insieme: tutte o nessuna, e si sa quale e stata rifiutata', function () {
    $s = new Staging(contentSite());
    expectStaging(fn() => $s->operateAll([
        ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'value' => ['it' => 'A', 'en' => 'A']],
        ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'inesistente', 'value' => 'x'],
    ], 'a'), 'Operazione 1 (set_field)');
    assertSame([], $s->operations());

    $done = $s->operateAll([
        ['op' => 'create_collection', 'name' => 'servizi', 'fields' => [['name' => 'titolo', 'type' => 'text']]],
        ['op' => 'create_entity', 'key' => 'servizi', 'fields' => ['titolo' => ['it' => 'Uno', 'en' => 'One']]],
    ], 'a');
    assertSame(0, $done[1]['assigned_id']);
    assertSame(2, count($s->operations()));
});

test('un caricamento si toglie dallo staging, non se un campo in attesa lo usa', function () {
    $root = contentSite();
    $s = new Staging($root);
    $tmp = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($tmp, base64_decode(KRIS_TEST_PNG));
    $unused = $s->upload($tmp, 'scarto.png', 'a');
    $used = $s->upload($tmp, 'logo.png', 'a');
    @unlink($tmp);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'logo', 'value' => ['it' => $used, 'en' => $used]], 'a');

    $s->deleteUpload($unused, 'a');
    assertTrue(!is_file("{$root}/data/staging/uploads/" . basename($unused)));
    assertSame([basename($used)], array_keys($s->manifest()['uploads']));
    expectStaging(fn() => $s->deleteUpload($used, 'a'), 'cambia prima quel campo');
    expectStaging(fn() => $s->deleteUpload($unused, 'a'), 'non è fra i file caricati');
    expectStaging(fn() => $s->deleteUpload('template/homepage.html', 'a'), 'Si tolgono solo');
});

test('gli avvisi segnalano campi senza sezione, campi non usati e font mancanti', function () {
    $root = contentSite();
    mkdir("{$root}/assets/css", 0777, true);
    file_put_contents("{$root}/assets/css/tema.css", "@font-face { font-family: X; src: url('../fonts/manca.woff2') format('woff2'); }");
    $s = new Staging($root);
    $s->operateAll([
        ['op' => 'create_collection', 'name' => 'servizi', 'fields' => [
            ['name' => 'titolo', 'type' => 'text', 'section' => 'Testata'],
            ['name' => 'sottotitolo', 'type' => 'text'],
        ]],
    ], 'a');
    $s->write('template/servizi.html', '<h1>{{titolo}}</h1>', null, 'a');

    $warnings = implode("\n", $s->warnings());
    assertContains('«servizi»: sottotitolo senza sezione', $warnings);
    assertContains('«servizi»: nessun template usa sottotitolo.', $warnings);
    assertNotContains('usa titolo', $warnings);
    assertContains('assets/css/tema.css cita il font manca.woff2', $warnings);
});

test('interruttori e scelte: condivisi o per lingua, con le opzioni dello schema', function () {
    $root = contentSite();
    $s = new Staging($root);
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'in_vetrina', 'type' => 'boolean']], 'a');
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'visibile', 'type' => 'boolean', 'localized' => true]], 'a');
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'tag', 'type' => 'select', 'multiple' => true, 'options' => ['web', 'app', 'seo']]], 'a');
    $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'stato', 'type' => 'select', 'options' => ['bozza', 'pronto']]], 'a');
    expectStaging(fn() => $s->operate(['op' => 'add_field', 'collection' => 'homepage', 'field' => ['name' => 'vuota', 'type' => 'select']], 'a'), 'servono le opzioni');

    $set = fn(string $field, mixed $value, ?string $lang = null) => $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => $field, 'value' => $value] + ($lang ? ['lang' => $lang] : []), 'a');
    $set('in_vetrina', true);
    $set('visibile', false);
    $set('visibile', true, 'en');
    $set('tag', ['seo', 'web']);
    $set('stato', 'pronto');
    $data = "{$root}/data/staging/data/content.json";
    assertSame(true, siteValue($data, 'homepage', 0, 'in_vetrina'));
    assertSame(['it' => false, 'en' => true], siteValue($data, 'homepage', 0, 'visibile'));
    assertSame(['web', 'seo'], siteValue($data, 'homepage', 0, 'tag'), 'nell ordine delle opzioni');
    assertSame('pronto', siteValue($data, 'homepage', 0, 'stato'));

    expectStaging(fn() => $set('tag', 'mobile'), 'non ha l\'opzione');
    expectStaging(fn() => $set('stato', ['bozza', 'pronto']), 'una sola opzione');
    expectStaging(fn() => $set('in_vetrina', 'forse'), 'interruttore');
    expectStaging(fn() => $set('in_vetrina', true, 'it'), 'togli "lang"');
});

// --- richtext filtrato (specifica 2.5) -------------------------------------------

test('richtext: restano elementi e attributi editoriali, script e gestori se ne vanno', function () {
    assertSame('<p>Ciao <strong>mondo</strong> è qui</p>', \Kris\Template\Richtext::clean('<p>Ciao <strong>mondo</strong> è qui</p>'));
    assertSame('<p>prima dopo</p>', \Kris\Template\Richtext::clean('<p>prima <script>alert(1)</script>dopo</p>'));
    assertSame('<img src="assets/uploads/a.png" alt="x">', \Kris\Template\Richtext::clean('<img src="assets/uploads/a.png" alt="x" onerror="fetch(1)">'));
    assertSame('<a>x</a>', \Kris\Template\Richtext::clean('<a href="javascript:alert(1)">x</a>'));
    assertSame('<a>x</a>', \Kris\Template\Richtext::clean('<a href="  JAVASCRIPT:alert(1)">x</a>'));
    assertSame('<a href="https://esempio.it" target="_blank" rel="noopener">x</a>', \Kris\Template\Richtext::clean('<a href="https://esempio.it" target="_blank">x</a>'));
    assertSame('<a href="mailto:a@b.it">m</a> <a href="#sopra">a</a>', \Kris\Template\Richtext::clean('<a href="mailto:a@b.it">m</a> <a href="#sopra">a</a>'));
    assertSame('<p>dentro</p>', \Kris\Template\Richtext::clean('<div style="color:red"><p>dentro</p></div>'), 'un div lascia il suo contenuto');
    assertSame('<span class="nota">n</span>', \Kris\Template\Richtext::clean('<span class="nota" style="x" id="y">n</span>'));
    assertSame('<img alt="x">', \Kris\Template\Richtext::clean('<img src="http://altro.it/a.png" alt="x">'), 'immagini solo del sito o https');
    assertSame('<table><tr><td colspan="2">c</td></tr></table>', \Kris\Template\Richtext::clean('<table><tr><td colspan="2" onclick="x">c</td></tr></table>'));
    assertSame('', \Kris\Template\Richtext::clean('<iframe src="https://x.it"></iframe>'));
    assertSame('testo semplice', \Kris\Template\Richtext::clean('testo semplice'));
});

test('richtext: filtrato al rendering e al salvataggio dello staging', function () {
    $e = makeEntity([['name' => 'body', 'type' => 'richtext', 'value' => ['it' => '<p onclick="x()">Ciao</p><script>y()</script>']]]);
    assertSame('<div><p>Ciao</p></div>', trim(renderTemplate('<div>{{body}}</div>', $e)));
    $root = contentSite();
    $s = new \Kris\Staging\Staging($root);
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'hero_description', 'lang' => 'it', 'value' => '<p>Ok<img src="x" onerror="z()"></p>'], 'agente');
    assertSame('<p>Ok<img src="x"></p>', siteValue("{$root}/data/staging/data/content.json", 'homepage', 0, 'hero_description')['it']);
});
