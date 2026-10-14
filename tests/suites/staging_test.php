<?php
declare(strict_types=1);

/**
 * Staging: modifiche ai file del tema preparate e non pubbliche, anteprima
 * solo per gli admin, pubblicazione con copia di sicurezza e annullamento.
 * Ogni test lavora su un sito temporaneo, mai sui file della repository.
 */

use Kris\Staging\Diff;
use Kris\Staging\FilePolicy;
use Kris\Staging\Preview;
use Kris\Staging\Staging;
use Kris\Staging\StagingException;

/** Sito temporaneo con un template e un foglio di stile. */
function stagingSite(): string
{
    $root = sys_get_temp_dir() . '/kris-staging-' . bin2hex(random_bytes(4));
    mkdir("{$root}/template", 0777, true);
    mkdir("{$root}/assets/css", 0777, true);
    mkdir("{$root}/data", 0777, true);
    file_put_contents("{$root}/template/home.html", "<h1>Chi siamo</h1>\n<p>Testo</p>\n");
    file_put_contents("{$root}/assets/css/style.css", "body { color: black; }\n");
    register_shutdown_function(fn() => rrmdir($root));
    return $root;
}

function assertThrowsStaging(callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (StagingException $e) {
        if ($contains !== '') assertContains($contains, $e->getMessage());
        return;
    }
    fail('era attesa una StagingException');
}

test('una modifica finisce nello staging e il sito non cambia', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $read = $s->read('template/home.html');
    $s->edit('template/home.html', 'Chi siamo', 'Il team', $read['hash'], 'test');

    assertSame("<h1>Chi siamo</h1>\n<p>Testo</p>\n", file_get_contents("{$root}/template/home.html"));
    assertSame("<h1>Il team</h1>\n<p>Testo</p>\n", $s->read('template/home.html')['content']);
    assertSame('staging', $s->read('template/home.html')['source']);
    assertTrue($s->exists());
});

test('solo template e fogli di stile del tema si possono modificare', function () {
    $s = new Staging(stagingSite());
    foreach (['kris/public.php', 'index.php', 'template/home.php', '.htaccess', 'data/k_data.json', 'config/auth.php',
        'template/../kris/x.html', 'assets/js/app.js', 'template/Home.html', 'assets/css/sub/x.css'] as $path) {
        assertThrowsStaging(fn() => $s->write($path, 'x', null, 'test'), 'non si può modificare');
    }
});

test('il contenuto non puo contenere PHP', function () {
    $root = stagingSite();
    $s = new Staging($root);
    assertThrowsStaging(fn() => $s->write('template/nuovo.html', '<p><?php echo 1; ?></p>', null, 'test'), 'PHP');
    assertThrowsStaging(fn() => $s->write('template/nuovo.html', '<p><?= 1 ?></p>', null, 'test'), 'PHP');
});

test('una scrittura basata su una versione vecchia viene rifiutata', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $old = $s->read('template/home.html')['hash'];
    $s->edit('template/home.html', 'Testo', 'Primo', $old, 'agente-a');

    assertThrowsStaging(fn() => $s->edit('template/home.html', 'Chi siamo', 'Secondo', $old, 'agente-b'), 'rileggilo');
});

test('il testo da sostituire deve comparire una sola volta', function () {
    $root = stagingSite();
    file_put_contents("{$root}/template/doppio.html", "<p>uno</p><p>uno</p>");
    $s = new Staging($root);
    $hash = $s->read('template/doppio.html')['hash'];
    assertThrowsStaging(fn() => $s->edit('template/doppio.html', 'uno', 'due', $hash, 'test'), '2 volte');
    assertThrowsStaging(fn() => $s->edit('template/doppio.html', 'tre', 'due', $hash, 'test'), 'non compare');
});

test('un file nuovo si crea con impronta nulla', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $s->write('assets/css/extra.css', '.x { color: red; }', null, 'test');
    $changes = $s->changes();
    assertSame('new', $changes[0]['kind']);
    assertSame(false, $changes[0]['conflict']);
    assertThrowsStaging(fn() => $s->write('assets/css/altro.css', 'x', 'impronta-inventata', 'test'), 'non esiste');
});

test('la pubblicazione copia i file, salva le versioni precedenti e svuota lo staging', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $s->edit('template/home.html', 'Chi siamo', 'Il team', $s->read('template/home.html')['hash'], 'test');
    $s->write('assets/css/extra.css', '.x {}', null, 'test');

    $done = $s->promote('admin');

    assertSame(['assets/css/extra.css', 'template/home.html'], $done['files']);
    assertSame("<h1>Il team</h1>\n<p>Testo</p>\n", file_get_contents("{$root}/template/home.html"));
    assertSame('.x {}', file_get_contents("{$root}/assets/css/extra.css"));
    assertSame(false, $s->exists());
    assertSame($done['backup'], $s->lastPromotion()['id']);
});

test('annullare la pubblicazione rimette i file e toglie quelli nuovi', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $s->edit('template/home.html', 'Chi siamo', 'Il team', $s->read('template/home.html')['hash'], 'test');
    $s->write('assets/css/extra.css', '.x {}', null, 'test');
    $s->promote('admin');

    $restored = $s->undoLastPromotion();

    assertSame(2, count($restored));
    assertSame("<h1>Chi siamo</h1>\n<p>Testo</p>\n", file_get_contents("{$root}/template/home.html"));
    assertSame(false, is_file("{$root}/assets/css/extra.css"));
    assertSame(null, $s->lastPromotion());
    assertThrowsStaging(fn() => $s->undoLastPromotion(), 'da annullare');
});

test('un file cambiato sul sito nel frattempo blocca la pubblicazione, salvo conferma', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $s->edit('template/home.html', 'Chi siamo', 'Il team', $s->read('template/home.html')['hash'], 'test');
    file_put_contents("{$root}/template/home.html", "<h1>Chi siamo</h1>\n<p>Cambiato dal cliente</p>\n");

    assertSame(true, $s->changes()[0]['conflict']);
    try {
        $s->promote('admin');
        fail('la pubblicazione doveva fermarsi');
    } catch (StagingException $e) {
        assertSame(['template/home.html: cambiato anche sul sito'], $e->details());
    }
    $s->promote('admin', true);
    assertContains('Il team', (string) file_get_contents("{$root}/template/home.html"));
});

test('scartare elimina lo staging senza toccare il sito', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $s->edit('template/home.html', 'Chi siamo', 'Il team', $s->read('template/home.html')['hash'], 'test');
    $s->discard();
    assertSame(false, $s->exists());
    assertSame([], $s->changes());
    assertContains('Chi siamo', (string) file_get_contents("{$root}/template/home.html"));
    assertThrowsStaging(fn() => $s->promote('admin'), 'Non ci sono modifiche');
});

test('gli elementi sensibili segnalati sono solo quelli aggiunti', function () {
    $root = stagingSite();
    file_put_contents("{$root}/template/home.html", '<script src="https://cdn.esistente.it/a.js"></script><p>x</p>');
    $s = new Staging($root);
    $s->edit('template/home.html', '<p>x</p>', '<p onclick="go()">x</p><a href="javascript:alert(1)">y</a><img src="https://tracker.example/p.gif">', $s->read('template/home.html')['hash'], 'test');

    $sensitive = $s->changes()[0]['sensitive'];
    assertTrue(in_array('gestore di eventi: onclick', $sensitive, true));
    assertTrue(in_array('URL javascript:', $sensitive, true));
    assertTrue(in_array('dominio esterno: tracker.example', $sensitive, true));
    assertTrue(!in_array('dominio esterno: cdn.esistente.it', $sensitive, true), 'il dominio era gia nel sito');
});

test('il diff mostra righe tolte e aggiunte con poco contesto', function () {
    $old = implode("\n", range(1, 20));
    $new = str_replace("\n10\n", "\ndieci\n", $old);
    $lines = Diff::lines($old, $new, 1);
    assertTrue(in_array(['-', '10'], $lines, true));
    assertTrue(in_array(['+', 'dieci'], $lines, true));
    assertTrue(in_array([' ', '9'], $lines, true));
    assertTrue(!in_array([' ', '2'], $lines, true), 'righe lontane compresse');
    assertSame(['…', ''], $lines[0]);
});

test('l anteprima riscrive i fogli di stile modificati e i link interni', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $s->write('assets/css/style.css', 'body{}', $s->read('assets/css/style.css')['hash'], 'test');
    $html = '<html><head><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/other.css"></head>'
        . '<body><a href="index.php?page=post&amp;id=2#c">Post</a><a href="index.php">Home</a><a href="https://altro.it">Esterno</a></body></html>';

    $out = Preview::decorate($html, $s, 'editor/index.php?action=staging', 'index.php?page=post');

    assertContains('href="index.php?kris_preview_asset=css/style.css"', $out);
    assertContains('href="assets/css/other.css"', $out);
    assertContains('href="index.php?page=post&amp;id=2&amp;kris_preview=1#c"', $out);
    assertContains('href="index.php?kris_preview=1"', $out);
    assertContains('href="https://altro.it"', $out);
    assertContains('<meta name="robots" content="noindex">', $out);
    assertContains('id="kris-preview-bar"', $out);
});

test('senza sessione dell editor l anteprima mostra il sito normale', function () {
    $sandbox = projectSandbox();
    $s = new Staging($sandbox);
    $file = 'template/homepage.html';
    $original = (string) file_get_contents("{$sandbox}/{$file}");
    $s->write($file, str_replace('</body>', '<p>SOLO-IN-STAGING</p></body>', $original), Staging::hash($original), 'test');
    try {
        $page = renderPage(['kris_preview' => '1']);
        assertSame(200, $page['status']);
        assertNotContains('SOLO-IN-STAGING', $page['output']);
        assertNotContains('kris-preview-bar', $page['output']);
        $asset = renderPage(['kris_preview_asset' => 'css/style.css']);
        assertNotContains('SOLO-IN-STAGING', $asset['output']);
    } finally {
        $s->discard();
    }
});

test('con la sessione dell editor l anteprima mostra lo staging e i suoi fogli di stile', function () {
    $sandbox = projectSandbox();
    $s = new Staging($sandbox);
    $file = 'template/homepage.html';
    $original = (string) file_get_contents("{$sandbox}/{$file}");
    $s->write($file, str_replace('</body>', '<p>SOLO-IN-STAGING</p></body>', $original), Staging::hash($original), 'test');
    $s->write('assets/css/anteprima.css', '.solo-staging { color: teal; }', null, 'test');
    try {
        $page = renderPage(['kris_preview' => '1'], null, 'index.php', true);
        assertSame(200, $page['status']);
        assertContains('SOLO-IN-STAGING', $page['output']);
        assertContains('id="kris-preview-bar"', $page['output']);

        $css = renderPage(['kris_preview_asset' => 'css/anteprima.css'], null, 'index.php', true);
        assertSame('.solo-staging { color: teal; }', $css['output']);
        $missing = renderPage(['kris_preview_asset' => '../../config/auth.php'], null, 'index.php', true);
        assertSame(404, $missing['status']);

        $normal = renderPage([], null, 'index.php', true);
        assertNotContains('SOLO-IN-STAGING', $normal['output'], 'senza ?kris_preview anche un admin vede il sito');
    } finally {
        $s->discard();
    }
});
