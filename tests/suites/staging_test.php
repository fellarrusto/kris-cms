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

test('solo i file del tema si possono modificare, mai il core', function () {
    $s = new Staging(stagingSite());
    foreach (['kris/public.php', 'index.php', 'template/home.php', '.htaccess', 'data/k_data.json', 'config/auth.php',
        'template/../kris/x.html', 'kris/editor/js/scripts.js', 'assets/js/sub/app.js', 'assets/js/app.php',
        'assets/images/logo.png', 'template/Home.html', 'assets/css/sub/x.css'] as $path) {
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

    assertContains('href="index.php?kris_preview_asset=css/style.css&kris_preview=1"', $out);
    assertContains('href="assets/css/other.css"', $out);
    assertContains('href="index.php?page=post&amp;id=2&amp;kris_preview=1#c"', $out);
    assertContains('href="index.php?kris_preview=1"', $out);
    assertContains('href="https://altro.it"', $out);
    assertContains('<meta name="robots" content="noindex">', $out);
    assertContains('id="kris-preview-bar"', $out);
});

test('senza sessione dell editor l anteprima risponde 404', function () {
    $sandbox = projectSandbox();
    $s = new Staging($sandbox);
    $file = 'template/homepage.html';
    $original = (string) file_get_contents("{$sandbox}/{$file}");
    $s->write($file, str_replace('</body>', '<p>SOLO-IN-STAGING</p></body>', $original), Staging::hash($original), 'test');
    try {
        $page = renderPage(['kris_preview' => '1']);
        assertSame(404, $page['status']);
        assertNotContains('SOLO-IN-STAGING', $page['output']);
        assertNotContains('kris-preview-bar', $page['output']);
        $asset = renderPage(['kris_preview_asset' => 'css/style.css']);
        assertSame(404, $asset['status']);
        assertNotContains('SOLO-IN-STAGING', $asset['output']);
        assertSame(200, renderPage([])['status'], 'senza il parametro il sito si vede come sempre');
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
        assertContains('le modifiche non ancora pubblicate', $page['output']);
        // La barra sta in cima al body, prima del contenuto del sito
        assertTrue(strpos($page['output'], 'kris-preview-bar') < strpos($page['output'], 'SOLO-IN-STAGING'));

        $css = renderPage(['kris_preview_asset' => 'css/anteprima.css'], null, 'index.php', true);
        assertSame('.solo-staging { color: teal; }', $css['output']);
        $missing = renderPage(['kris_preview_asset' => '../../config/auth.php'], null, 'index.php', true);
        assertSame(404, $missing['status']);

        $s->discard();
        $empty = renderPage(['kris_preview' => '1'], null, 'index.php', true);
        assertSame(200, $empty['status']);
        assertContains('Non ci sono modifiche in attesa', $empty['output'], 'anche senza modifiche si capisce che è un anteprima');

        $normal = renderPage([], null, 'index.php', true);
        assertNotContains('SOLO-IN-STAGING', $normal['output'], 'senza ?kris_preview anche un admin vede il sito');
    } finally {
        $s->discard();
    }
});

// --- JavaScript, SVG, eliminazioni e approvazione degli script -----------------

test('JavaScript e SVG del tema si scrivono; un SVG puo avere la dichiarazione XML ma non PHP', function () {
    $s = new Staging(stagingSite());
    $s->write('assets/js/menu.js', "if (a <? b) {}\ndocument.body.dataset.ok = '1';", null, 'test');
    $s->write('assets/images/logo.svg', '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"></svg>', null, 'test');
    assertSame('staging', $s->read('assets/js/menu.js')['source']);
    assertThrowsStaging(fn() => $s->write('assets/images/x.svg', '<svg><?php echo 1; ?></svg>', null, 'test'), 'PHP');
});

test('un file del tema si elimina alla pubblicazione e l annullamento lo rimette', function () {
    $root = stagingSite();
    file_put_contents("{$root}/assets/css/vecchio.css", 'p{}');
    $s = new Staging($root);
    assertThrowsStaging(fn() => $s->delete('assets/css/vecchio.css', 'impronta-sbagliata', 'test'), 'cambiato');
    $s->delete('assets/css/vecchio.css', Staging::hash('p{}'), 'test');
    assertSame(null, $s->read('assets/css/vecchio.css')['content']);
    assertSame(['assets/css/vecchio.css'], $s->deletedFiles());
    assertSame('deleted', $s->changes()[0]['kind']);
    assertTrue(is_file("{$root}/assets/css/vecchio.css"), 'il sito non cambia prima della pubblicazione');

    $s->promote('admin');
    assertTrue(!is_file("{$root}/assets/css/vecchio.css"));
    $s->undoLastPromotion();
    assertSame('p{}', file_get_contents("{$root}/assets/css/vecchio.css"));
});

test('un file nuovo eliminato prima di pubblicarlo sparisce dallo staging', function () {
    $s = new Staging(stagingSite());
    $s->write('assets/js/prova.js', 'x()', null, 'test');
    $s->delete('assets/js/prova.js', Staging::hash('x()'), 'test');
    assertSame([], $s->changes());
    assertSame(null, $s->read('assets/js/prova.js')['content']);
});

test('il template di una pagina pubblica non si elimina, un frammento ancora usato blocca la pubblicazione', function () {
    $root = stagingSite();
    file_put_contents("{$root}/template/homepage.html", '<div k-array="servizi" k-template="card"></div>');
    file_put_contents("{$root}/template/card.html", '<p>{{title}}</p>');
    $s = new Staging($root);
    assertThrowsStaging(fn() => $s->delete('template/homepage.html', Staging::hash('<div k-array="servizi" k-template="card"></div>'), 'test'), 'unregister_page');
    $s->delete('template/card.html', Staging::hash('<p>{{title}}</p>'), 'test');
    assertSame(['template/homepage.html usa ancora il template eliminato «card»'], $s->templateProblems());
});

test('JavaScript, SVG e script nei template aspettano l approvazione, che scade se il codice cambia', function () {
    $root = stagingSite();
    $s = new Staging($root);
    assertTrue($s->codeApproved(), 'senza codice nuovo non serve approvare');
    $s->write('assets/css/style.css', 'a{background:url(https://cdn.esempio.it/x.png)}', $s->read('assets/css/style.css')['hash'], 'test');
    assertSame([], $s->codeFiles(), 'un dominio esterno non e codice');

    $s->write('assets/js/menu.js', 'menu()', null, 'test');
    $s->edit('template/home.html', '<p>Testo</p>', '<p onclick="x()">Testo</p>', $s->read('template/home.html')['hash'], 'test');
    assertSame(['assets/js/menu.js', 'template/home.html'], $s->codeFiles());
    assertTrue(!$s->codeApproved());

    assertThrowsStaging(fn() => $s->approveCode('impronta-vecchia', 'admin'), 'cambiati');
    $s->approveCode((string) $s->codeFingerprint(), 'admin');
    assertTrue($s->codeApproved());

    $s->write('assets/js/menu.js', 'menu(); altro()', Staging::hash('menu()'), 'test');
    assertTrue(!$s->codeApproved(), 'codice cambiato: va riapprovato');
});

test('l anteprima con codice nuovo mostra il popup e serve il JavaScript solo dopo l approvazione', function () {
    $sandbox = projectSandbox();
    $s = new Staging($sandbox);
    $file = 'template/homepage.html';
    $original = (string) file_get_contents("{$sandbox}/{$file}");
    $s->write($file, str_replace('</body>', '<script src="assets/js/nuovo.js"></script></body>', $original), Staging::hash($original), 'test');
    $s->write('assets/js/nuovo.js', 'window.NUOVO = 1;', null, 'test');
    try {
        $page = renderPage(['kris_preview' => '1'], null, 'index.php', true);
        assertSame(200, $page['status']);
        assertContains('id="kris-code-approval"', $page['output']);
        assertContains('<li>assets/js/nuovo.js</li>', $page['output']);
        assertContains('name="approve_code"', $page['output']);
        assertContains('name="csrf" value="token-di-prova"', $page['output']);
        assertContains('required', $page['output'], 'serve spuntare l assunzione di responsabilita');
        assertContains('Anteprima senza script', $page['output']);
        assertContains('src="index.php?kris_preview_asset=js/nuovo.js&kris_preview=1"', $page['output']);
        assertSame(404, renderPage(['kris_preview_asset' => 'js/nuovo.js'], null, 'index.php', true)['status'], 'prima dell approvazione il file non si serve');

        $s->approveCode((string) $s->codeFingerprint(), 'admin');
        $approved = renderPage(['kris_preview' => '1'], null, 'index.php', true);
        assertNotContains('id="kris-code-approval"', $approved['output']);
        assertSame('window.NUOVO = 1;', renderPage(['kris_preview_asset' => 'js/nuovo.js'], null, 'index.php', true)['output']);
    } finally {
        $s->discard();
    }
});

test('nell anteprima il CSS in attesa tiene i suoi url() relativi', function () {
    $root = stagingSite();
    mkdir("{$root}/data/staging/assets/images", 0777, true);
    file_put_contents("{$root}/data/staging/assets/images/nuova.svg", '<svg/>');
    $css = Preview::rebaseCss('a{background:url("../images/marquee/x.svg")}b{background:url(../images/nuova.svg)}'
        . 'c{background:url(data:image/png;base64,AA==)}d{src:url(\'../fonts/inter.woff2?v=2#x\')}@import "base.css";e{background:url(https://x.it/a.png)}', new Staging($root));
    assertContains('url("assets/images/marquee/x.svg")', $css);
    assertContains('url(index.php?kris_preview_asset=images/nuova.svg&kris_preview=1)', $css);
    assertContains('url(data:image/png;base64,AA==)', $css);
    assertContains("url('assets/fonts/inter.woff2?v=2#x')", $css);
    assertContains('@import "assets/css/base.css"', $css);
    assertContains('url(https://x.it/a.png)', $css);
});

test('le pagine chiedono CSS e JS del tema con la versione del file', function () {
    $root = stagingSite();
    mkdir("{$root}/assets/js", 0777, true);
    file_put_contents("{$root}/assets/js/app.js", 'x()');
    touch("{$root}/assets/css/style.css", 1700000000);
    $html = '<link href="assets/css/style.css"><link href="assets/css/manca.css"><script src="./assets/js/app.js"></script>'
        . '<link href="assets/css/style.css?x=1"><img src="assets/uploads/a.png">';
    $out = \Kris\Template\Assets::version($html, $root);
    assertContains('href="assets/css/style.css?v=' . base_convert('1700000000', 10, 36) . '"', $out);
    assertContains('href="assets/css/manca.css"', $out);
    assertContains('src="./assets/js/app.js?v=', $out);
    assertContains('href="assets/css/style.css?x=1"', $out);
    assertContains('src="assets/uploads/a.png"', $out);
});

test('un font WOFF2 si carica nello staging, si vede in anteprima e va in assets/fonts alla pubblicazione', function () {
    $root = stagingSite();
    $s = new Staging($root);
    $font = tempnam(sys_get_temp_dir(), 'woff');
    file_put_contents($font, 'wOF2' . str_repeat("\0", 60));
    assertSame('assets/fonts/inter-regular.woff2', $s->uploadFont($font, 'Inter Regular.woff2', 'agente'));
    assertTrue($s->hasChanges());

    $fake = tempnam(sys_get_temp_dir(), 'woff');
    file_put_contents($fake, '<?php echo 1;');
    assertThrowsStaging(fn() => $s->uploadFont($fake, 'finto.woff2', 'agente'), 'WOFF2');
    assertThrowsStaging(fn() => $s->uploadFont($font, 'inter.ttf', 'agente'), '.woff2');

    $css = Preview::rebaseCss("@font-face{src:url('../fonts/inter-regular.woff2') format('woff2')}", $s);
    assertContains("url('index.php?kris_preview_asset=fonts/inter-regular.woff2&kris_preview=1')", $css);

    $s->promote('admin');
    assertSame('wOF2', substr((string) file_get_contents("{$root}/assets/fonts/inter-regular.woff2"), 0, 4));
    $s->undoLastPromotion();
    assertTrue(!is_file("{$root}/assets/fonts/inter-regular.woff2"), 'l annullamento toglie il font nuovo');
});
