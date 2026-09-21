<?php
declare(strict_types=1);

/**
 * Contratti di Kris\Entity\Entity e composizione DOM.
 * Verifica lettura dei valori e importazione completa dei frammenti HTML.
 */

use Kris\Template\DomHelper;

// --- comportamenti che devono restare tali ------------------------------

test('getData restituisce il valore di un campo plain', function () {
    $e = makeEntity([fieldPlain('codice', 'AB-12')]);
    assertSame('AB-12', $e->getData('codice'));
});

test('getData con lingua sceglie la traduzione giusta', function () {
    $e = makeEntity([fieldText('titolo', ['it' => 'Ciao', 'en' => 'Hello'])]);
    assertSame('Hello', $e->getData('titolo', 'en'));
});

test('getData su campo inesistente restituisce null', function () {
    assertSame(null, makeEntity([])->getData('boh'));
});

test('getArray restituisce i figli di un campo array', function () {
    $e = makeEntity([[
        'name' => 'figli',
        'type' => 'array',
        'value' => [['id' => 0, 'data' => []], ['id' => 1, 'data' => []]],
    ]]);
    assertSame(2, count($e->getArray('figli')));
});

test('getArray su campo non-array restituisce null', function () {
    assertSame(null, makeEntity([fieldPlain('x', 'y')])->getArray('x'));
});

test("l'id dell'entita e leggibile come campo", function () {
    assertSame(42, makeEntity([], 42)->getData('id'));
});

test('importHtml importa un frammento a radice singola', function () {
    $dom = DomHelper::loadHtml('<section></section>');
    $dom->documentElement->appendChild(DomHelper::importHtml($dom, '<div>uno</div>'));
    assertContains('<div>uno</div>', $dom->saveHTML());
});

// --- bug noti, da correggere --------------------------------------------

test('la stringa "0" e un valore valido, non un campo vuoto', function () {
    $e = makeEntity([fieldText('quantita', ['it' => '0', 'en' => 'zero'])]);
    assertSame('0', $e->getData('quantita', 'it'));
});

test('importHtml conserva tutti i nodi radice di un frammento', function () {
    $dom = DomHelper::loadHtml('<section></section>');
    $dom->documentElement->appendChild(DomHelper::importHtml($dom, '<div>uno</div><div>due</div>'));
    assertContains('<div>due</div>', $dom->saveHTML(), 'il secondo nodo radice e stato scartato');
});

test("l'output non contiene la processing instruction <?xml", function () {
    $out = renderTemplate('<p>{{n}}</p>', makeEntity([fieldPlain('n', '1')]));
    assertNotContains('<?xml', $out);
});
