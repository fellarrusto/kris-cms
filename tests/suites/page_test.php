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
        assertContains('path=features/' . $i, $out);
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

// --- snapshot ------------------------------------------------------------

test('snapshot: homepage (it)', function () {
    assertMatchesSnapshot('homepage_it', renderPage([])['output']);
});

test('snapshot: homepage (en)', function () {
    assertMatchesSnapshot('homepage_en', renderPage(['ln' => 'en'])['output']);
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
