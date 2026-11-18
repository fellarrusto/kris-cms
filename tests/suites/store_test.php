<?php
declare(strict_types=1);

/**
 * Archivio dei dati (Kris\Store): adapter JSON sui file del formato 1.
 * La stessa suite girerà sull'adapter database (fase 5).
 */

use Kris\Store\ConflictException;
use Kris\Store\FileStore;
use Kris\Store\StoreException;

function storeSite(): string
{
    $root = sys_get_temp_dir() . '/kris-store-' . bin2hex(random_bytes(4));
    mkdir("{$root}/data", 0777, true);
    mkdir("{$root}/config", 0777, true);
    copy(KRIS_TESTS . '/fixtures/k_data.json', "{$root}/data/k_data.json");
    copy(KRIS_TESTS . '/fixtures/k_model.json', "{$root}/data/k_model.json");
    file_put_contents("{$root}/config/allowed_pages.json", json_encode(['allowed_pages' => ['homepage']]));
    register_shutdown_function(fn() => rrmdir($root));
    return $root;
}

test('un documento si legge con la sua revisione; uno che non esiste e vuoto', function () {
    $s = new FileStore(storeSite());
    $data = $s->read('data');
    assertTrue(count($data->value) > 0);
    assertSame(64, strlen($data->rev));
    $settings = $s->read('settings');
    assertSame([], $settings->value);
    assertSame('', $settings->rev);
});

test('una scrittura con la revisione letta riesce, con una superata e rifiutata', function () {
    $root = storeSite();
    $s = new FileStore($root);
    $read = $s->read('pages');
    $rev = $s->write('pages', ['allowed_pages' => ['homepage', 'menu']], $read->rev);
    assertTrue($rev !== $read->rev);
    assertSame(['homepage', 'menu'], $s->read('pages')->value['allowed_pages']);

    // Un altro ha scritto dopo la nostra lettura: non lo sovrascriviamo
    try {
        $s->write('pages', ['allowed_pages' => ['homepage']], $read->rev);
        throw new RuntimeException('doveva essere un conflitto');
    } catch (ConflictException $e) {
        assertSame('pages', $e->doc);
        assertSame($rev, $e->currentRev);
    }
    assertSame(['homepage', 'menu'], $s->read('pages')->value['allowed_pages']);

    // Senza revisione si scrive comunque (comportamento della 1.x)
    $s->write('pages', ['allowed_pages' => ['homepage']]);
    assertSame(['homepage'], $s->read('pages')->value['allowed_pages']);
});

test('una transazione scrive solo i documenti cambiati, e solo quelli letti', function () {
    $root = storeSite();
    $s = new FileStore($root);
    $before = (string) file_get_contents("{$root}/data/k_model.json");
    $s->transaction(['data', 'model'], function (array $docs): array {
        $docs['data'][0]['hidden'] = true;
        return ['data' => $docs['data']];
    });
    assertSame(true, $s->read('data')->value[0]['hidden']);
    assertSame($before, (string) file_get_contents("{$root}/data/k_model.json"), 'il documento non cambiato non si riscrive');

    try {
        $s->transaction(['data'], fn(array $docs): array => ['seo' => ['head' => false]]);
        throw new RuntimeException('doveva essere rifiutata');
    } catch (StoreException $e) {
        assertContains('non ha letto', $e->getMessage());
    }
    assertSame([], $s->read('seo')->value);
});

test('esportazione e importazione passano tutti i documenti da un sito a un altro', function () {
    $from = new FileStore(storeSite());
    $from->write('settings', ['languages' => ['it', 'de']]);
    $docs = $from->export();
    assertSame(['data', 'model', 'settings', 'pages'], array_keys($docs));

    $toRoot = sys_get_temp_dir() . '/kris-store-vuoto-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => rrmdir($toRoot));
    $to = new FileStore($toRoot);
    $to->import($docs);
    assertSame($docs, $to->export());
});

test('un documento sconosciuto o un file rovinato sono errori, non dati vuoti', function () {
    $root = storeSite();
    $s = new FileStore($root);
    try {
        $s->read('segreti');
        throw new RuntimeException('doveva essere rifiutato');
    } catch (StoreException $e) {
        assertContains('sconosciuto', $e->getMessage());
    }
    file_put_contents("{$root}/data/k_data.json", '{"rotto":');
    try {
        $s->read('data');
        throw new RuntimeException('doveva essere un errore');
    } catch (\Kris\Entity\StorageException $e) {
        assertContains('non valido', $e->getMessage());
    }
});
