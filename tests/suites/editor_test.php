<?php
declare(strict_types=1);

/**
 * L'editor sul formato 2: lettura dei contenuti, modulo di modifica,
 * Struttura tradotta in operazioni, salvataggio sul sito pubblico (Live).
 */

use Kris\Staging\Live;
use Kris\Staging\Operations;
use Kris\Staging\StagingException;

if (!defined('KRIS_EDITOR')) define('KRIS_EDITOR', true);
require_once KRIS_ROOT . '/kris/editor/helpers.php';
require_once KRIS_ROOT . '/kris/editor/model.php';

function assertThrows(callable $fn, string $class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        assertTrue($e instanceof $class, 'eccezione ' . get_class($e) . ': ' . $e->getMessage());
        return;
    }
    assertTrue(false, "doveva lanciare {$class}");
}

function editorFixture(): array
{
    return [
        'schema' => ['format' => 2, 'types' => [
            'menu' => ['label' => 'Menu', 'newest_first' => true, 'fields' => [
                ['name' => 'nome', 'type' => 'text', 'localized' => true, 'description' => 'Nome del piatto'],
                ['name' => 'prezzo', 'type' => 'text', 'localized' => false, 'section' => 'Prezzi'],
                ['name' => 'novita', 'type' => 'boolean', 'localized' => false],
                ['name' => 'allergeni', 'type' => 'select', 'localized' => false, 'options' => ['glutine', 'lattosio', 'solfiti'], 'multiple' => true],
                ['name' => 'varianti', 'type' => 'list', 'of' => [
                    ['name' => 'etichetta', 'type' => 'text', 'localized' => true],
                ]],
            ]],
            'kris_site' => ['reserved' => true, 'single' => true, 'fields' => [['name' => 'site_name', 'type' => 'text', 'localized' => false]]],
            'contatti' => ['single' => true, 'fields' => [['name' => 'telefono', 'type' => 'text', 'localized' => false]]],
        ], 'pages' => []],
        'content' => [
            ['type' => 'menu', 'id' => 0, 'values' => ['nome' => ['it' => 'Spritz', 'en' => 'Spritz'], 'prezzo' => '6,50', 'novita' => false,
                'allergeni' => ['solfiti'], 'varianti' => [['id' => 0, 'values' => ['etichetta' => ['it' => 'Analcolico', 'en' => 'Alcohol free']]]]]],
            ['type' => 'contatti', 'id' => 0, 'values' => ['telefono' => '0123']],
            ['type' => 'menu', 'id' => 1, 'hidden' => true, 'values' => ['nome' => ['it' => 'Negroni', 'en' => ''], 'prezzo' => '8', 'novita' => true,
                'allergeni' => ['glutine', 'solfiti'], 'varianti' => []]],
            ['type' => 'kris_site', 'id' => 0, 'values' => ['site_name' => 'Bar Centrale']],
        ],
        'settings' => ['languages' => ['it', 'en']],
    ];
}

function editorSiteRoot(): string
{
    $root = sys_get_temp_dir() . '/kris-editor-' . bin2hex(random_bytes(4));
    mkdir("{$root}/assets/uploads", 0777, true);
    writeFormat2($root, editorFixture());
    register_shutdown_function(fn() => rrmdir($root));
    return $root;
}

test('editor: tipi del sito senza quelli riservati, contenuti per tipo e per percorso', function () {
    editorSite(editorFixture());
    assertSame(['menu', 'contatti'], array_keys(editorUserTypes()));
    assertTrue(isReservedCollection('kris_site') && isReservedCollection('plg_stats_visite') && !isReservedCollection('menu'));
    assertSame('Menu', collectionLabel('menu'));
    assertSame('Contatti', collectionLabel('contatti'));
    assertTrue(isNewestFirst('menu') && isSingleCollection('contatti') && !isSingleCollection('menu'));
    assertSame([0, 1], array_column(editorItems('menu'), 'id'));
    assertSame('Analcolico', editorNode('menu', 0, ['varianti', '0'])['values']['etichetta']['it']);
    assertSame(null, editorNode('menu', 0, ['varianti', '9']));
    assertSame(null, editorNode('menu', 0, ['varianti']));
    assertSame(['etichetta'], array_column(editorFields('menu', ['varianti']), 'name'));
    assertSame([], editorFields('menu', ['prezzo']), 'un campo che non è un elenco non ha campi');
    assertSame('Spritz', editorTitle(editorItem('menu', 0), editorFields('menu')));
    assertSame('Contenuto #0', editorTitle(['id' => 0, 'values' => ['prezzo' => '']], []));
    // Il titolo ignora le scelte e gli elenchi
    assertSame('Contenuto #4', editorTitle(['id' => 4, 'values' => ['allergeni' => ['glutine']]], editorFields('menu')));
});

test('editor: il modulo diventa valori del formato 2, senza toccare quello che non manda', function () {
    $fields = editorFixture()['schema']['types']['menu']['fields'];
    $before = editorFixture()['content'][0]['values'] + ['extra' => 'resta'];
    $before['nome']['de'] = 'Spritz DE';
    $post = ['f' => [
        'nome' => ['it' => "Spritz\r\ndella casa"],
        'prezzo' => ['val' => '7'],
        'novita' => ['val' => '1'],
        'allergeni' => ['val' => ['', 'lattosio', 'inventato', 'glutine']],
        'varianti' => ['val' => 'ignorato'],
    ]];
    $after = editorPostedValues($fields, $post, ['it', 'en'], $before);
    assertSame(['it' => "Spritz\ndella casa", 'en' => 'Spritz', 'de' => 'Spritz DE'], $after['nome'], 'la lingua non inviata e quella disattivata restano');
    assertSame('7', $after['prezzo']);
    assertSame(true, $after['novita']);
    assertSame(['glutine', 'lattosio'], $after['allergeni'], 'solo opzioni ammesse, nel loro ordine');
    assertSame($before['varianti'], $after['varianti'], 'gli elenchi si cambiano con le loro azioni');
    assertSame('resta', $after['extra']);
    // Interruttore spento: arriva solo il campo nascosto vuoto
    assertSame(false, editorPostedValues($fields, ['f' => ['novita' => ['val' => '']]], ['it'], $before)['novita']);
});

test('struttura: campi tolti, aggiunti, cambiati, descritti, opzioni e ordine diventano operazioni', function () {
    $old = editorFixture()['schema']['types']['menu']['fields'];
    $new = editorCleanSchema([
        ['name' => 'prezzo', 'type' => 'text', 'localized' => true, 'description' => 'In euro', 'section' => ''],
        ['name' => 'nome', 'type' => 'text', 'localized' => true, 'description' => 'Nome del piatto'],
        ['name' => 'novita', 'type' => 'select', 'localized' => false, 'options' => ['si', 'no']],
        ['name' => 'allergeni', 'type' => 'select', 'localized' => false, 'options' => ['glutine', 'solfiti'], 'multiple' => true],
        ['name' => 'varianti', 'type' => 'list', 'of' => [
            ['name' => 'etichetta', 'type' => 'text', 'localized' => true],
            ['name' => 'extra', 'type' => 'text', 'localized' => false],
        ]],
        ['name' => 'foto', 'type' => 'media', 'localized' => false],
    ]);
    assertSame([], validateSchema($new));
    $ops = editorSchemaOps('menu', $old, $new);
    $by = fn(string $op) => array_values(array_filter($ops, fn($o) => $o['op'] === $op));
    assertSame([['op' => 'change_type', 'name' => 'prezzo', 'type' => 'text', 'localized' => true, 'collection' => 'menu']], $by('change_type'));
    assertSame(['novita', 'novita', 'extra', 'foto'], array_merge(array_column($by('remove_field'), 'name'), array_column(array_column($by('add_field'), 'field'), 'name')), 'da interruttore a scelta: il campo si ricrea');
    assertSame([['op' => 'set_options', 'name' => 'allergeni', 'options' => ['glutine', 'solfiti'], 'multiple' => true, 'collection' => 'menu']], $by('set_options'));
    assertSame(['op' => 'describe_field', 'name' => 'prezzo', 'description' => 'In euro', 'section' => '', 'collection' => 'menu'], $by('describe_field')[0]);
    assertSame([['varianti']], array_values(array_column(array_filter($by('add_field'), fn($o) => isset($o['path'])), 'path')), 'un campo nuovo in un elenco');

    // Applicate in ordine, portano lo schema esattamente a quello voluto
    $state = editorFixture();
    foreach ($ops as $op) [$state] = Operations::apply($state, $op, ['it', 'en'], fn() => true);
    assertSame(array_column($new, 'name'), array_column($state['schema']['types']['menu']['fields'], 'name'));
    assertSame(['it' => '6,50', 'en' => '6,50'], $state['content'][0]['values']['prezzo'], 'condiviso → per lingua: lo stesso testo in ogni lingua');
    assertSame(['solfiti'], $state['content'][0]['values']['allergeni']);
    assertSame(['glutine', 'solfiti'], $state['content'][2]['values']['allergeni']);
    assertSame([], editorSchemaOps('menu', $state['schema']['types']['menu']['fields'], $new), 'nessuna differenza, nessuna operazione');
});

test('struttura: si segnala solo quello che si perde davvero', function () {
    editorSite(editorFixture());
    $old = editorFields('menu');
    $new = array_values(array_filter($old, fn($f) => !in_array($f['name'], ['prezzo', 'novita'], true)));
    $new[] = ['name' => 'novita', 'type' => 'text', 'localized' => false];
    $impact = editorSchemaImpact('menu', $old, $new);
    assertSame(['prezzo', 'novita'], array_column($impact, 'path'));
    assertSame(2, $impact[0]['count']);
    assertSame(1, $impact[1]['count'], 'un interruttore spento non è un valore');
    // Un'opzione tolta che qualcuno ha scelto
    $fewer = $old;
    $fewer[3]['options'] = ['solfiti'];
    assertSame(['allergeni'], array_column(editorSchemaImpact('menu', $old, $fewer), 'path'));
    // Campo di un elenco mai compilato: niente da segnalare
    $nested = $old;
    $nested[4]['of'] = [];
    assertSame(['varianti / etichetta'], array_column(editorSchemaImpact('menu', $old, $nested), 'path'));
    assertSame([], editorSchemaImpact('menu', $old, $old));
});

test('struttura: nomi, tipi e opzioni non validi si rifiutano', function () {
    $problems = validateSchema(editorCleanSchema([
        ['name' => 'Titolo', 'type' => 'text'],
        ['name' => 'group', 'type' => 'text'],
        ['name' => 'scelta', 'type' => 'select', 'options' => ['a,b']],
        ['name' => 'vuota', 'type' => 'select', 'options' => []],
        ['name' => 'x', 'type' => 'date'],
        ['name' => 'x', 'type' => 'text'],
    ]));
    assertSame(6, count($problems), implode("\n", $problems));
});

test('set_options: le opzioni tolte spariscono dai contenuti, anche per lingua e da multipla a singola', function () {
    $state = editorFixture();
    [$state] = Operations::apply($state, ['op' => 'set_options', 'collection' => 'menu', 'name' => 'allergeni', 'options' => ['solfiti', 'sedano'], 'multiple' => false], ['it', 'en'], fn() => true);
    assertSame(['solfiti', 'sedano'], $state['schema']['types']['menu']['fields'][3]['options']);
    assertSame(false, $state['schema']['types']['menu']['fields'][3]['multiple']);
    assertSame('solfiti', $state['content'][0]['values']['allergeni']);
    assertSame('solfiti', $state['content'][2]['values']['allergeni'], 'resta la prima opzione ancora ammessa');

    $state['schema']['types']['menu']['fields'][3]['localized'] = true;
    $state['content'][0]['values']['allergeni'] = ['it' => 'solfiti', 'en' => 'sedano'];
    [$state] = Operations::apply($state, ['op' => 'set_options', 'collection' => 'menu', 'name' => 'allergeni', 'options' => ['sedano'], 'multiple' => true], ['it', 'en'], fn() => true);
    assertSame(['it' => [], 'en' => ['sedano']], $state['content'][0]['values']['allergeni']);

    assertThrows(fn() => Operations::apply($state, ['op' => 'set_options', 'collection' => 'menu', 'name' => 'prezzo', 'options' => ['a']], ['it'], fn() => true), StagingException::class);
    assertThrows(fn() => Operations::apply($state, ['op' => 'set_options', 'collection' => 'menu', 'name' => 'allergeni', 'options' => []], ['it'], fn() => true), StagingException::class);
});

test('Live: le operazioni si applicano subito al sito, tutte o nessuna', function () {
    $root = editorSiteRoot();
    $done = Live::apply($root, [
        ['op' => 'set_field', 'key' => 'menu', 'id' => 0, 'field' => 'nome', 'lang' => 'it', 'value' => 'Spritz della casa'],
        ['op' => 'create_entity', 'key' => 'menu'],
        ['op' => 'set_hidden', 'key' => 'menu', 'id' => 1, 'hidden' => false],
    ]);
    assertSame(2, $done[1]['assigned_id']);
    $site = \Kris\Store\Site::current($root);
    assertSame('Spritz della casa', $site['content'][0]['values']['nome']['it']);
    assertSame([0, 1, 2], array_column(array_values(array_filter($site['content'], fn($e) => $e['type'] === 'menu')), 'id'));
    assertTrue(!isset($site['content'][2]['hidden']));
    $settings = (string) file_get_contents("{$root}/data/settings.json");

    // La seconda operazione è sbagliata: non si scrive nemmeno la prima
    $content = (string) file_get_contents("{$root}/data/content.json");
    assertThrows(fn() => Live::apply($root, [
        ['op' => 'set_field', 'key' => 'menu', 'id' => 0, 'field' => 'prezzo', 'value' => '9'],
        ['op' => 'remove_entity', 'key' => 'contatti', 'id' => 0],
    ]), StagingException::class);
    assertSame($content, (string) file_get_contents("{$root}/data/content.json"));
    assertSame($settings, (string) file_get_contents("{$root}/data/settings.json"), 'un documento che non cambia non si riscrive');

    // Le sezioni di Kris accettano i valori, non la struttura
    Live::apply($root, [['op' => 'set_field', 'key' => 'kris_site', 'id' => 0, 'field' => 'site_name', 'value' => 'Bar Nuovo']]);
    $content = \Kris\Store\Site::current($root)['content'];
    assertSame('Bar Nuovo', array_values(array_filter($content, fn($e) => $e['type'] === 'kris_site'))[0]['values']['site_name']);
    assertSame(['menu', 'contatti', 'menu', 'menu', 'kris_site'], array_column($content, 'type'), 'un contenuto nuovo va subito dopo l ultimo del suo tipo');
    assertThrows(fn() => Live::apply($root, [['op' => 'add_field', 'collection' => 'kris_site', 'field' => ['name' => 'x', 'type' => 'text']]]), StagingException::class);
    // Un media deve essere un file caricato
    assertThrows(fn() => Live::apply($root, [['op' => 'add_field', 'collection' => 'menu', 'field' => ['name' => 'foto', 'type' => 'media', 'localized' => false]],
        ['op' => 'set_field', 'key' => 'menu', 'id' => 0, 'field' => 'foto', 'value' => 'assets/uploads/manca.jpg']]), StagingException::class);
    touch("{$root}/assets/uploads/c'e.jpg");
    file_put_contents("{$root}/assets/uploads/spritz.jpg", 'x');
    Live::apply($root, [['op' => 'add_field', 'collection' => 'menu', 'field' => ['name' => 'foto', 'type' => 'media', 'localized' => false]],
        ['op' => 'set_field', 'key' => 'menu', 'id' => 0, 'field' => 'foto', 'value' => 'assets/uploads/spritz.jpg']]);
    assertSame('assets/uploads/spritz.jpg', \Kris\Store\Site::current($root)['content'][0]['values']['foto']);
});

test('dopo un eliminazione si torna all elemento indicato dal modulo, solo se e un id valido', function () {
    $_POST['return_to'] = 'item-archive-12';
    assertSame('#item-archive-12', returnAnchor());
    foreach (['', 'item"><script>', "list-x\r\nLocation: https://example.com", str_repeat('a', 200)] as $bad) {
        $_POST['return_to'] = $bad;
        assertSame('', returnAnchor(), 'rifiutato: ' . substr($bad, 0, 20));
    }
    unset($_POST['return_to']);
    assertSame('', returnAnchor());
});

test('i campi si raggruppano per sezione: quelli senza in testa, poi nell ordine di comparsa', function () {
    $schema = [
        ['name' => 'a', 'type' => 'text', 'section' => 'Info'],
        ['name' => 'b', 'type' => 'text'],
        ['name' => 'c', 'type' => 'text', 'section' => 'Meta'],
        ['name' => 'd', 'type' => 'text', 'section' => 'Info'],
    ];
    $sections = schemaSections($schema);
    assertSame(['', 'Info', 'Meta'], array_column($sections, 'section'));
    assertSame([1], array_keys($sections[0]['fields']));
    // Gli indici restano quelli dello schema: servono agli id dei campi
    assertSame([0, 3], array_keys($sections[1]['fields']));
    assertSame(['Info'], array_column(schemaSections([$schema[0]]), 'section'));
    assertSame([], schemaSections([]));
    // Le sezioni si ripuliscono e valgono solo al primo livello
    $clean = editorCleanSchema([['name' => 'x', 'type' => 'list', 'section' => '  Meta   pagina ', 'of' => [['name' => 'y', 'type' => 'text', 'section' => 'No']]]]);
    assertSame('Meta pagina', $clean[0]['section']);
    assertTrue(!isset($clean[0]['of'][0]['section']));
});
