<?php
declare(strict_types=1);

/**
 * L'editor sulle modifiche in attesa: le sue azioni diventano operazioni dello
 * staging (EditorChanges), con le operazioni che servono solo all'editor
 * (eliminare e spostare un contenuto, sospenderlo) e i salvataggi ripetuti
 * fusi in una sola modifica. Usa contentSite() di staging_content_test.php.
 */

use Kris\Staging\EditorChanges;
use Kris\Staging\Staging;

function stagedIds(string $root, string $key): array
{
    $file = "{$root}/data/staging/data/k_data.json";
    return array_map(fn($e) => (int) $e['id'], array_values(array_filter(
        json_decode((string) file_get_contents($file), true),
        fn($e) => $e['name'] === $key
    )));
}

function addPage(string $root, int $id, string $title): void
{
    $file = "{$root}/data/k_data.json";
    $data = json_decode((string) file_get_contents($file), true);
    $data[] = ['name' => 'navbar', 'id' => $id, 'data' => [['name' => 'page_title', 'type' => 'text', 'value' => ['it' => $title, 'en' => $title]]]];
    file_put_contents($file, json_encode($data));
}

test('fieldOps cambia solo le lingue toccate, i plain per intero, e ignora gli elenchi', function () {
    $schema = [
        ['name' => 'title', 'type' => 'text'], ['name' => 'code', 'type' => 'plain'],
        ['name' => 'items', 'type' => 'array'], ['name' => 'same', 'type' => 'text'],
    ];
    $before = [
        ['name' => 'title', 'type' => 'text', 'value' => ['it' => 'Ciao', 'en' => 'Hi', 'de' => 'Hallo']],
        ['name' => 'code', 'type' => 'plain', 'value' => 'a'],
        ['name' => 'items', 'type' => 'array', 'value' => [['id' => 0, 'data' => []]]],
        ['name' => 'same', 'type' => 'text', 'value' => ['it' => 'x', 'en' => 'y']],
    ];
    $after = [
        ['name' => 'title', 'type' => 'text', 'value' => ['it' => 'Salve', 'en' => 'Hi', 'de' => 'Hallo']],
        ['name' => 'code', 'type' => 'plain', 'value' => 'b'],
        ['name' => 'items', 'type' => 'array', 'value' => []],
        ['name' => 'same', 'type' => 'text', 'value' => ['it' => 'x', 'en' => 'y']],
    ];

    $ops = EditorChanges::fieldOps('homepage', 0, ['steps', 1], $schema, $before, $after, ['it', 'en']);

    assertSame([
        ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 1], 'field' => 'title', 'lang' => 'it', 'value' => 'Salve'],
        ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 1], 'field' => 'code', 'value' => 'b'],
    ], $ops);
});

test('moves porta un ordine nell altro con uno spostamento per elemento fuori posto', function () {
    assertSame([], EditorChanges::moves([0, 1, 2], [0, 1, 2]));
    assertSame([[2, 0]], EditorChanges::moves([0, 1, 2], [2, 0, 1]));
    assertSame([[1, 0]], EditorChanges::moves([0, 1, 2], [1, 0, 2]));
    // Duplicati e id sconosciuti ignorati, come nel riordino dell'editor
    assertSame([[2, 0]], EditorChanges::moves([0, 1, 2], [2, 2, 9]));
    // Applicati in sequenza, gli spostamenti danno l'ordine chiesto
    $order = [5, 3, 8, 1];
    $wanted = [1, 8, 5, 3];
    foreach (EditorChanges::moves($order, $wanted) as [$id, $to]) {
        array_splice($order, (int) array_search($id, $order, true), 1);
        array_splice($order, $to, 0, [$id]);
    }
    assertSame($wanted, $order);
});

test('salvare piu volte lo stesso campo lascia una sola modifica, e tornare al valore di partenza la toglie', function () {
    $root = contentSite();
    $s = new Staging($root);
    $original = siteValue("{$root}/data/k_data.json", 'homepage', 0, 'page_title')['it'];
    $set = fn(string $value, string $field = 'page_title') => ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => $field, 'lang' => 'it', 'value' => $value];

    $s->operate($set('Primo'), 'editor');
    $s->operate($set('Altro', 'page_subtitle'), 'editor');
    $s->operate($set('Secondo'), 'editor');

    $ops = $s->operations();
    assertSame(2, count($ops));
    $title = array_values(array_filter($ops, fn($o) => $o['field'] === 'page_title'))[0];
    assertSame('Secondo', $title['value']);
    assertSame($original, $title['before'], 'il controllo dei conflitti resta sul valore di partenza');

    $s->operate($set($original), 'editor');
    assertSame(['page_subtitle'], array_column($s->operations(), 'field'));
});

test('dopo un elemento aggiunto le modifiche allo stesso campo non si fondono piu', function () {
    $s = new Staging(contentSite());
    $set = fn(string $value) => ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => $value];
    $s->operate($set('Uno'), 'editor');
    $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps'], 'editor');
    $s->operate($set('Due'), 'editor');
    assertSame(['set_field', 'add_item', 'set_field'], array_column($s->operations(), 'op'));
});

test('operateAll registra tutte le operazioni o nessuna', function () {
    $s = new Staging(contentSite());
    expectStaging(fn() => $s->operateAll([
        ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => 'Ok'],
        ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'inesistente', 'value' => 'x'],
    ], 'editor'), 'non esiste');
    assertSame([], $s->operations());
});

test('eliminare, spostare e sospendere contenuti radice e elementi, fino alla pubblicazione', function () {
    $root = contentSite();
    addPage($root, 1, 'Seconda');
    addPage($root, 2, 'Terza');
    $s = new Staging($root);

    $s->operate(['op' => 'move_entity', 'key' => 'navbar', 'id' => 2, 'to' => 0], 'editor');
    assertSame([2, 0, 1], stagedIds($root, 'navbar'));
    $s->operate(['op' => 'remove_entity', 'key' => 'navbar', 'id' => 0], 'editor');
    assertSame([2, 1], stagedIds($root, 'navbar'));
    $s->operate(['op' => 'set_hidden', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 1], 'hidden' => true], 'editor');
    $s->operate(['op' => 'set_hidden', 'key' => 'navbar', 'id' => 1, 'hidden' => true], 'editor');
    expectStaging(fn() => $s->operate(['op' => 'remove_entity', 'key' => 'kris_legal', 'id' => 0], 'editor'), 'appartiene a Kris');
    expectStaging(fn() => $s->operate(['op' => 'remove_entity', 'key' => 'navbar', 'id' => 0], 'editor'), 'non esiste più');

    // Il sito non cambia finche non si pubblica
    assertSame([0, 1, 2], array_map(fn($e) => (int) $e['id'], array_values(array_filter(json_decode((string) file_get_contents("{$root}/data/k_data.json"), true), fn($e) => $e['name'] === 'navbar'))));

    $s->promote('admin');
    $site = json_decode((string) file_get_contents("{$root}/data/k_data.json"), true);
    $navbars = array_values(array_filter($site, fn($e) => $e['name'] === 'navbar'));
    assertSame([2, 1], array_map(fn($e) => (int) $e['id'], $navbars));
    assertTrue(!empty($navbars[1]['hidden']), 'navbar 1 sospesa');
    $steps = siteValue("{$root}/data/k_data.json", 'homepage', 0, 'steps');
    assertTrue(!empty($steps[1]['hidden']), 'il passo 1 è sospeso');
    assertTrue(empty($steps[0]['hidden']), 'gli altri restano visibili');
    // Le altre raccolte restano dove sono
    assertSame('homepage', $site[0]['name']);
});

test('un elemento nuovo tiene alla pubblicazione l id avuto nell anteprima', function () {
    $root = contentSite();
    $s = new Staging($root);
    $done = $s->operate(['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps'], 'editor');
    $s->operate(['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', $done['assigned_id']], 'field' => 'title', 'lang' => 'it', 'value' => 'Nuovo passo'], 'editor');

    // Intanto sul sito sparisce l'ultimo passo: l'id libero piu alto cambia
    $file = "{$root}/data/k_data.json";
    $data = json_decode((string) file_get_contents($file), true);
    foreach ($data[0]['data'] as &$field) {
        if ($field['name'] === 'steps') array_pop($field['value']);
    }
    unset($field);
    file_put_contents($file, json_encode($data));

    $s->promote('admin');
    $steps = siteValue($file, 'homepage', 0, 'steps');
    $new = array_values(array_filter($steps, fn($c) => (int) $c['id'] === $done['assigned_id']));
    assertSame(1, count($new));
    assertSame('Nuovo passo', $new[0]['data'][0]['value']['it']);
});

test('un id gia preso sul sito nel frattempo e un conflitto, non un elemento sovrascritto', function () {
    $root = contentSite();
    addPage($root, 1, 'Seconda');
    $s = new Staging($root);
    $done = $s->operate(['op' => 'create_entity', 'key' => 'navbar'], 'editor');
    assertSame(2, $done['assigned_id']);
    addPage($root, 2, 'Creata dal cliente');
    expectStaging(fn() => $s->promote('admin'), 'esiste già un contenuto con id 2');
});

test('i file caricati nello staging si riconoscono finche non sono pubblici', function () {
    $root = contentSite();
    $s = new Staging($root);
    $tmp = tempnam(sys_get_temp_dir(), 'kris-png');
    file_put_contents($tmp, base64_decode(KRIS_TEST_PNG));
    $url = $s->upload($tmp, 'foto.png', 'editor');
    @unlink($tmp);

    assertTrue($s->isStagedUpload($url));
    assertSame([basename($url)], array_map('basename', $s->uploadFiles()));
    assertTrue(!$s->isStagedUpload('assets/uploads/altro.png'));
    assertTrue(!$s->isStagedUpload('../../config/auth.php'));
});
