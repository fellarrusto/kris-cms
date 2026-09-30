<?php
declare(strict_types=1);

/**
 * Versione del framework e stato del sito rispetto agli aggiornamenti.
 * Lo stato si legge da file temporanei: i test non toccano mai data/.
 */

use Kris\Entity\StorageException;
use Kris\Update\SiteState;

/** File di stato temporaneo con il contenuto dato, cancellato a fine suite. */
function stateFile(?string $content): string
{
    $file = sys_get_temp_dir() . '/kris-state-' . getmypid() . '-' . uniqid() . '.json';
    if ($content !== null) file_put_contents($file, $content);
    register_shutdown_function(fn() => @unlink($file));
    return $file;
}

test('la versione del framework e un numero semver', function () {
    assertTrue(
        (bool) preg_match('/^\d+\.\d+\.\d+$/', SiteState::frameworkVersion()),
        'kris/VERSION deve contenere solo una versione tipo 1.2.3'
    );
});

test('un sito senza file di stato ottiene lo stato vuoto', function () {
    $state = SiteState::load(stateFile(null));
    assertSame(null, $state['data_version']);
    assertSame([], $state['migrations']);
    assertSame([], $state['history']);
});

test('i campi presenti nel file prevalgono sui default', function () {
    $state = SiteState::load(stateFile('{"data_version": 3, "migrations": ["0001_a"]}'));
    assertSame(3, $state['data_version']);
    assertSame(['0001_a'], $state['migrations']);
    assertSame(null, $state['last_check']);
});

test('un file di stato rovinato e un errore, non uno stato vuoto', function () {
    try {
        SiteState::load(stateFile('{"data_version": '));
    } catch (StorageException) {
        return;
    }
    fail('mi aspettavo una StorageException');
});

test("l'ultimo aggiornamento e l'ultima voce del registro", function () {
    $state = ['history' => [
        ['from' => '1.0.0', 'to' => '1.0.1', 'outcome' => 'ok'],
        ['from' => '1.0.1', 'to' => '1.1.0', 'outcome' => 'rollback'],
    ]];
    assertSame('1.1.0', SiteState::lastUpdate($state)['to']);
    assertSame(null, SiteState::lastUpdate(['history' => []]));
});

test('array_is_list funziona anche dove PHP 8.0 non la fornisce', function () {
    // Su PHP 8.0 questo esercita il rimpiazzo di kris/bootstrap.php.
    assertSame(true, array_is_list([]));
    assertSame(true, array_is_list(['a', 'b']));
    assertSame(false, array_is_list([1 => 'a']));
    assertSame(false, array_is_list(['x' => 1]));
    assertSame(false, array_is_list([0 => 'a', 2 => 'b']));
});
