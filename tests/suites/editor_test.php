<?php
declare(strict_types=1);

/**
 * Funzioni pure del pannello: sezioni dei campi e fonti della sezione Posts.
 */

if (!defined('KRIS_EDITOR')) define('KRIS_EDITOR', true);
require_once KRIS_ROOT . '/kris/editor/helpers.php';

test('le sezioni si ripuliscono, vuote si tolgono, anche negli elenchi', function () {
    $schema = normalizeSections([
        ['name' => 'title', 'type' => 'text', 'section' => '  Meta   pagina '],
        ['name' => 'phone', 'type' => 'plain', 'section' => ''],
        ['name' => 'items', 'type' => 'array', 'section' => str_repeat('x', 80), 'of' => [
            ['name' => 'label', 'type' => 'text', 'section' => 'Testi'],
            ['name' => 'url', 'type' => 'plain', 'section' => 3],
        ]],
    ]);
    assertSame('Meta pagina', $schema[0]['section']);
    assertTrue(!array_key_exists('section', $schema[1]));
    assertSame(60, mb_strlen($schema[2]['section']));
    assertSame('Testi', $schema[2]['of'][0]['section']);
    assertTrue(!array_key_exists('section', $schema[2]['of'][1]));
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
});

test('in Posts le raccolte scelte, poi gli elenchi; solo raccolte del sito esistenti', function () {
    $models = [
        'homepage' => [['name' => 'posts', 'type' => 'array', 'posts' => true, 'of' => []]],
        'progetti' => [['name' => 'title', 'type' => 'text']],
        'kris_legal' => [],
    ];
    $data = [
        ['name' => 'progetti', 'id' => 0, 'data' => []],
        ['name' => 'homepage', 'id' => 0, 'data' => [['name' => 'posts', 'type' => 'array', 'value' => [['id' => 0, 'data' => []]]]]],
        ['name' => 'progetti', 'id' => 4, 'data' => []],
    ];
    $collections = editorPostCollections(['post_collections' => ['progetti', 'manca', 'kris_legal', 'progetti']], $models);
    assertSame(['progetti'], $collections);
    $sources = editorPostSources($models, $data, $collections);
    assertSame(2, count($sources));
    assertSame(['progetti', -1, ''], [$sources[0]['group'], $sources[0]['id'], $sources[0]['field']]);
    assertSame([0, 4], array_column($sources[0]['items'], 'id'));
    assertSame(['homepage', 0, 'posts'], [$sources[1]['group'], $sources[1]['id'], $sources[1]['field']]);
    assertSame([], editorPostCollections([], $models));
});
