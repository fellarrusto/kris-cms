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

test('nomi dei file caricati: prefisso del sito, niente nomi da fotocamera', function () {
    $dir = sys_get_temp_dir() . '/kris-nomi-' . bin2hex(random_bytes(3)) . '/';
    mkdir($dir);
    register_shutdown_function(fn() => rrmdir($dir));
    $pdf = $dir . 'src.pdf';
    file_put_contents($pdf, "%PDF-1.4\n%prova");
    $store = new \Kris\Entity\MediaStore($dir, 'assets/uploads/', ['prefix' => 'Studio Romano']);
    assertTrue(preg_match('#^assets/uploads/studio-romano-[0-9a-f]{8}\.pdf$#', $store->storeLocal($pdf, 'IMG_0042.pdf')) === 1);
    assertTrue(preg_match('#^assets/uploads/studio-romano-listino-prezzi-[0-9a-f]{8}\.pdf$#', $store->storeLocal($pdf, 'Listino Prezzi.pdf')) === 1);
    $plain = new \Kris\Entity\MediaStore($dir, 'assets/uploads/', []);
    assertTrue(preg_match('#^assets/uploads/file-[0-9a-f]{8}\.pdf$#', $plain->storeLocal($pdf, 'DSC_1234.pdf')) === 1);
    assertTrue(\Kris\Entity\MediaStore::isCameraName('img-20240512-wa0003'));
    assertTrue(!\Kris\Entity\MediaStore::isCameraName('studio-reception'));
});

test('con GD le foto JPEG si raddrizzano, perdono i metadati e si riducono', function () {
    if (!\Kris\Entity\MediaStore::canProcessImages()) {
        echo "    (GD non disponibile: si controlla solo che il file resti com'e)\n";
        return;
    }
    $dir = sys_get_temp_dir() . '/kris-foto-' . bin2hex(random_bytes(3)) . '/';
    mkdir($dir);
    register_shutdown_function(fn() => rrmdir($dir));
    $im = imagecreatetruecolor(3000, 1000);
    ob_start(); imagejpeg($im); $jpeg = (string) ob_get_clean();
    $ifd = pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0) . 'GPSDATA';
    $tiff = 'II' . pack('v', 42) . pack('V', 8) . $ifd;
    file_put_contents($dir . 'src.jpg', substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff . substr($jpeg, 2));
    $url = (new \Kris\Entity\MediaStore($dir, 'assets/uploads/', ['max_width' => 800]))->storeLocal($dir . 'src.jpg', 'foto.jpg');
    $out = (string) file_get_contents($dir . basename($url));
    [$w, $h] = getimagesize($dir . basename($url));
    assertSame([800, 2400], [$w, $h], 'raddrizzata (1000x3000) e ridotta a 800 di larghezza');
    assertTrue(!str_contains($out, 'GPSDATA') && !str_contains($out, "Exif\0\0"), 'metadati tolti');
});

test('i video MP4 e WebM si caricano fino a 50 MB, i .mov no', function () {
    $dir = sys_get_temp_dir() . '/kris-video-' . bin2hex(random_bytes(3)) . '/';
    mkdir($dir);
    register_shutdown_function(fn() => rrmdir($dir));
    $store = new \Kris\Entity\MediaStore($dir, 'assets/uploads/', []);
    file_put_contents($dir . 'a.mp4', "\0\0\0\x18ftypmp42" . str_repeat("\0", 32));
    assertTrue(preg_match('#^assets/uploads/sfondo-[0-9a-f]{8}\.mp4$#', $store->storeLocal($dir . 'a.mp4', 'sfondo.mp4')) === 1);
    file_put_contents($dir . 'b.webm', "\x1A\x45\xDF\xA3" . str_repeat("\0", 32));
    assertTrue(str_ends_with($store->storeLocal($dir . 'b.webm', 'loop.webm'), '.webm'));
    file_put_contents($dir . 'finto.mp4', '<?php echo 1;');
    expectStorage(fn() => $store->storeLocal($dir . 'finto.mp4', 'finto.mp4'), 'non corrisponde');
    expectStorage(fn() => $store->storeLocal($dir . 'a.mp4', 'clip.mov'), 'MP4');
    assertSame(50 * 1024 * 1024, \Kris\Entity\MediaStore::maxBytesFor('mp4'));
    assertSame(8 * 1024 * 1024, \Kris\Entity\MediaStore::maxBytesFor('jpg'));
});

function expectStorage(callable $fn, string $contains): void
{
    try {
        $fn();
    } catch (\Kris\Entity\StorageException $e) {
        assertContains($contains, $e->getMessage());
        return;
    }
    fail('era attesa una StorageException');
}
