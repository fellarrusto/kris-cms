<?php
declare(strict_types=1);

/**
 * Kris\Site\Paths: da dove il rendering pubblico legge template, contenuti e
 * pagine. Senza sovrapposizione sono i file del sito; con la sovrapposizione
 * (l'anteprima dello staging) vince il file sovrapposto, se esiste.
 */

use Kris\Site\Paths;

/** Cartella temporanea con i file dati, e la sovrapposizione tolta alla fine. */
function withOverlay(array $files, callable $fn): void
{
    $dir = sys_get_temp_dir() . '/kris-overlay-' . bin2hex(random_bytes(4));
    foreach ($files as $relative => $content) {
        if (!is_dir(dirname("{$dir}/{$relative}"))) mkdir(dirname("{$dir}/{$relative}"), 0777, true);
        file_put_contents("{$dir}/{$relative}", $content);
    }
    Paths::useOverlay($dir);
    try {
        $fn($dir);
    } finally {
        Paths::useOverlay(null);
        rrmdir($dir);
    }
}

test('senza sovrapposizione i percorsi sono quelli del sito', function () {
    assertSame(null, Paths::overlay());
    assertSame(KRIS_ROOT . '/template/homepage.html', Paths::template('homepage'));
    assertSame(KRIS_ROOT . '/data/k_data.json', Paths::data('k_data'));
    assertSame(KRIS_ROOT . '/config/allowed_pages.json', Paths::file('config/allowed_pages.json'));
});

test('un file presente nella sovrapposizione vince su quello del sito', function () {
    withOverlay(['template/homepage.html' => '<p>staging</p>'], function (string $dir) {
        assertSame($dir . '/template/homepage.html', Paths::template('homepage'));
    });
});

test('un file assente dalla sovrapposizione resta quello del sito', function () {
    withOverlay(['template/altro.html' => '<p>x</p>'], function () {
        assertSame(KRIS_ROOT . '/template/homepage.html', Paths::template('homepage'));
        assertSame(KRIS_ROOT . '/data/k_data.json', Paths::data('k_data'));
    });
});

test('il rendering legge template e contenuti dalla sovrapposizione', function () {
    $data = [['name' => 'riquadro', 'id' => 0, 'data' => [fieldPlain('titolo', 'Titolo in staging')]]];
    withOverlay([
        'data/k_data.json' => json_encode($data),
        'template/riquadro.html' => '<section class="riquadro"><h2>{{titolo}}</h2></section>',
    ], function () {
        $html = renderTemplate('<main><div k-component="riquadro" k-template="riquadro" k-index="0"></div></main>', makeEntity([]));
        assertContains('<section class="riquadro"><h2>Titolo in staging</h2></section>', $html);
    });
});

test('togliere la sovrapposizione torna ai file del sito', function () {
    withOverlay(['template/homepage.html' => '<p>staging</p>'], fn() => null);
    assertSame(null, Paths::overlay());
    assertSame(KRIS_ROOT . '/template/homepage.html', Paths::template('homepage'));
});
