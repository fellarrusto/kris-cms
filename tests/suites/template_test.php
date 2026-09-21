<?php
declare(strict_types=1);

/**
 * Motore dei template: interpolazione e condizioni.
 * Verifica escaping, contesto linguistico e condizioni annidate.
 */

$entity = fn() => makeEntity([
    fieldPlain('n', '7'),
    fieldPlain('categoria', 'feature_a'),
    fieldPlain('tags', 'tech, ui'),
    fieldText('titolo', ['it' => 'Ciao', 'en' => 'Hello']),
    fieldText('vuoto_it', ['it' => '', 'en' => 'Fallback']),
]);

// --- comportamenti che devono restare tali ------------------------------

test('interpola una variabile semplice', function () use ($entity) {
    assertContains('<p>7</p>', renderTemplate('<p>{{n}}</p>', $entity()));
});

test('interpola un campo multilingua nella lingua richiesta', function () use ($entity) {
    assertContains('<p>Hello</p>', renderTemplate('<p>{{titolo}}</p>', $entity(), 'en'));
});

test('se la traduzione manca ricade su una lingua compilata', function () use ($entity) {
    assertContains('<p>Fallback</p>', renderTemplate('<p>{{vuoto_it}}</p>', $entity(), 'it'));
});

test('{{language}} restituisce la lingua corrente', function () use ($entity) {
    assertContains('<p>en</p>', renderTemplate('<p>{{language}}</p>', $entity(), 'en'));
});

test('una variabile inesistente diventa stringa vuota', function () use ($entity) {
    assertContains('<p></p>', renderTemplate('<p>{{non_esiste}}</p>', $entity()));
});

test('condizione == su campo plain', function () use ($entity) {
    assertContains('SI', renderTemplate('<p>{{#if categoria == "feature_a"}}SI{{#else}}NO{{/if}}</p>', $entity()));
});

test('condizione != su campo plain', function () use ($entity) {
    assertContains('SI', renderTemplate('<p>{{#if categoria != "altro"}}SI{{#else}}NO{{/if}}</p>', $entity()));
});

test('ramo #elif', function () use ($entity) {
    $html = '<p>{{#if categoria == "x"}}A{{#elif categoria == "feature_a"}}B{{#else}}C{{/if}}</p>';
    assertContains('B', renderTemplate($html, $entity()));
});

test('ramo #else', function () use ($entity) {
    assertContains('C', renderTemplate('<p>{{#if categoria == "x"}}A{{#else}}C{{/if}}</p>', $entity()));
});

test('operatore has su lista di tag', function () use ($entity) {
    assertContains('SI', renderTemplate('<p>{{#if tags has "tech"}}SI{{#else}}NO{{/if}}</p>', $entity()));
});

test('operatore has: tag assente', function () use ($entity) {
    assertContains('NO', renderTemplate('<p>{{#if tags has "altro"}}SI{{#else}}NO{{/if}}</p>', $entity()));
});

test('operatore > su valori numerici', function () use ($entity) {
    assertContains('SI', renderTemplate('<p>{{#if n > 5}}SI{{#else}}NO{{/if}}</p>', $entity()));
});

// --- bug noti, da correggere --------------------------------------------

test('operatore >=', function () use ($entity) {
    assertContains('SI', renderTemplate('<p>{{#if n >= 5}}SI{{#else}}NO{{/if}}</p>', $entity()));
});

test('operatore <=', function () use ($entity) {
    // n vale 7: 7 <= 5 e falso, quindi deve uscire NO
    assertContains('NO', renderTemplate('<p>{{#if n <= 5}}SI{{#else}}NO{{/if}}</p>', $entity()));
});

test('un {{#if}} annidato non perde il proprio contenuto', function () use ($entity) {
    $html = '<p>{{#if categoria == "feature_a"}}A{{#if n == "7"}}B{{/if}}C{{/if}}</p>';
    assertContains('ABC', renderTemplate($html, $entity()));
});

test('condizione su campo multilingua usa la lingua corrente', function () use ($entity) {
    $html = '<p>{{#if titolo == "Ciao"}}SI{{#else}}NO{{/if}}</p>';
    assertContains('SI', renderTemplate($html, $entity(), 'it'));
});

test('un campo text non deve produrre HTML', function () {
    $e = makeEntity([fieldText('titolo', ['it' => 'Ciao <em>mondo</em>', 'en' => ''])]);
    $out = renderTemplate('<p>{{titolo}}</p>', $e, 'it');
    assertNotContains('<em>', $out, 'il valore di un campo text e finito nel markup');
});

test('un valore non deve poter uscire da un attributo', function () {
    $e = makeEntity([fieldPlain('slug', 'x" data-iniettato="1')]);
    $out = renderTemplate('<a href="?p={{slug}}">x</a>', $e);

    // Il controllo va fatto sul DOM: cercare la stringa nell'HTML darebbe un
    // falso allarme, perche compare (innocua) dentro il valore dell'attributo.
    $dom = new DOMDocument();
    @$dom->loadHTML('<div>' . $out . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $link = $dom->getElementsByTagName('a')->item(0);

    assertTrue($link !== null, 'il link non e stato reso');
    assertSame(false, $link->hasAttribute('data-iniettato'), 'il valore ha creato un attributo nuovo');
    assertSame(1, $link->attributes->length, 'il tag ha piu attributi del previsto');
});
