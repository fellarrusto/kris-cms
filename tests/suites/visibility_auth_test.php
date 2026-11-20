<?php
declare(strict_types=1);

/**
 * Elementi sospesi ("hidden": true) e recupero della password.
 */

use Kris\Auth\Credentials;
use Kris\Auth\PasswordReset;
use Kris\Auth\UserException;
use Kris\Auth\Users;
use Kris\Template\Seo;

/** Copia del sito di test con la feature features/1 sospesa. */
function hiddenSandbox(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $dir = sys_get_temp_dir() . '/kris-hidden-' . getmypid();
    rrmdir($dir);
    rcopy(projectSandbox(), $dir);
    $file = $dir . '/data/k_data.json';
    $data = json_decode(file_get_contents($file), true);
    foreach ($data as &$entity) {
        if ($entity['name'] !== 'homepage') continue;
        foreach ($entity['data'] as &$field) {
            if ($field['name'] !== 'features') continue;
            foreach ($field['value'] as &$child) {
                if ((int) $child['id'] === 1) $child['hidden'] = true;
            }
        }
    }
    unset($entity, $field, $child);
    file_put_contents($file, json_encode($data));
    register_shutdown_function(fn() => rrmdir($dir));
    return $dir;
}

/** Titolo (in italiano) della feature con l'ID dato, dalle fixture. */
function featureTitle(int $id): string
{
    $data = json_decode(file_get_contents(KRIS_TESTS . '/fixtures/k_data.json'), true);
    foreach ($data[0]['data'] as $field) {
        if ($field['name'] !== 'features') continue;
        foreach ($field['value'] as $child) {
            if ((int) $child['id'] !== $id) continue;
            foreach ($child['data'] as $f) {
                if ($f['name'] === 'title') return $f['value']['it'];
            }
        }
    }
    return '';
}

test('un elemento sospeso sparisce dalla lista del sito, gli altri restano', function () {
    $html = renderPage(['page' => 'homepage'], hiddenSandbox())['output'];
    assertNotContains(featureTitle(1), $html);
    assertContains(featureTitle(0), $html);
    assertContains(featureTitle(2), $html);
});

test('la pagina di un elemento sospeso risponde 404', function () {
    assertSame(404, renderPage(['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/1'], hiddenSandbox())['status']);
    assertSame(200, renderPage(['page' => 'detail', 'key' => 'homepage', 'id' => '0', 'path' => 'features/2'], hiddenSandbox())['status']);
});

test('gli elementi sospesi non sono nella sitemap', function () {
    $content = [
        ['type' => 'progetti', 'id' => 0, 'values' => []],
        ['type' => 'progetti', 'id' => 1, 'hidden' => true, 'values' => []],
        ['type' => 'homepage', 'id' => 0, 'values' => ['posts' => [
            ['id' => 0, 'values' => []], ['id' => 1, 'hidden' => true, 'values' => []],
        ]]],
    ];
    $entries = Seo::sitemapEntries(['pages' => [
        'progetto' => ['type' => 'progetti', 'sitemap' => true],
        'post' => ['type' => 'homepage', 'item' => 0, 'list' => 'posts', 'sitemap' => true],
    ]], $content);
    assertSame([['progetto', 'progetti', 0, []], ['post', 'homepage', 0, ['posts', '0']]], $entries);
});

// --- credenziali e reset ------------------------------------------------------

function tempFile(string $name): string
{
    $file = sys_get_temp_dir() . '/kris-auth-' . getmypid() . '-' . uniqid() . '-' . $name;
    register_shutdown_function(fn() => @unlink($file));
    return $file;
}

test('le credenziali si salvano, si verificano e conservano i campi non toccati', function () {
    $c = new Credentials(tempFile('auth.php'));
    assertTrue($c->save(['user' => 'admin', 'password' => 'password-lunga', 'email' => 'a@b.it', 'editor_url' => 'https://x.it/editor/index.php']));
    assertTrue($c->verify('admin', 'password-lunga'));
    assertSame(false, $c->verify('admin', 'sbagliata'));
    assertTrue($c->save(['password' => 'nuova-password']));
    assertTrue($c->verify('admin', 'nuova-password'));
    assertSame('a@b.it', $c->load()['email'], 'cambiare password non cancella l\'email');
});

test('un file di credenziali della 1.1.0, senza email, si legge ancora', function () {
    $file = tempFile('auth.php');
    file_put_contents($file, "<?php return " . var_export(['user' => 'admin', 'hash' => password_hash('vecchia-password', PASSWORD_DEFAULT)], true) . ";");
    $c = new Credentials($file);
    assertTrue($c->verify('admin', 'vecchia-password'));
    assertSame('', $c->load()['email']);
});

test('il link di reset vale una volta sola, per 30 minuti, e dice di quale utente è', function () {
    $r = new PasswordReset(tempFile('reset.json'));
    $now = 1_000_000;
    $token = $r->create('mario', $now);
    assertSame('mario', $r->userOf($token, $now + 60));
    assertSame(null, $r->userOf($token, $now + PasswordReset::TTL + 1), 'scaduto');
    assertSame(null, $r->userOf(str_repeat('a', 64), $now), 'token inventato');
    assertSame('mario', $r->consume($token, $now + 60));
    assertSame(null, $r->consume($token, $now + 61), 'gia usato');
});

test('un nuovo link annulla il precedente dello stesso utente, non quelli degli altri', function () {
    $r = new PasswordReset(tempFile('reset.json'));
    $first = $r->create('mario', 1000);
    $other = $r->create('anna', 1005);
    $second = $r->create('mario', 1010);
    assertSame(null, $r->userOf($first, 1020));
    assertSame('mario', $r->userOf($second, 1020));
    assertSame('anna', $r->userOf($other, 1020));
});

test('al massimo tre richieste l ora per utente', function () {
    $r = new PasswordReset(tempFile('reset.json'));
    foreach ([0, 10, 20] as $t) $r->create('mario', 5000 + $t);
    assertSame(false, $r->canRequest('mario', 5030));
    assertSame(true, $r->canRequest('anna', 5030));
    assertSame(true, $r->canRequest('mario', 5000 + 3601));
});

test('sul disco resta solo l hash del token', function () {
    $file = tempFile('reset.json');
    $token = (new PasswordReset($file))->create('mario');
    assertNotContains($token, (string) file_get_contents($file));
});

// --- utenti ------------------------------------------------------------------

function usersFile(): string
{
    return tempFile('users.php');
}

test('utenti: l admin crea utenti con password ed email, nomi ed email unici', function () {
    $file = usersFile();
    $u = new Users($file);
    assertSame(false, $u->exists());
    $u->create('Mario', 'password-lunga', 'mario@esempio.it', 'admin');
    $u->create('anna', 'password-lunga', 'anna@esempio.it');
    assertSame(['anna', 'mario'], array_keys($u->all()), 'i nomi sono in minuscolo, in ordine');
    assertSame('base', $u->get('anna')['role']);
    assertTrue(!isset($u->get('anna')['hash']), 'l hash non esce mai');
    assertSame('ok', $u->check('anna', 'password-lunga'));
    assertSame('invalid', $u->check('anna', 'sbagliata'));
    assertSame('invalid', $u->check('nessuno', 'password-lunga'));
    assertSame('anna', $u->byEmail(' ANNA@esempio.it '));
    foreach ([
        fn() => $u->create('anna', 'password-lunga', ''),
        fn() => $u->create('a', 'password-lunga', ''),
        fn() => $u->create('nome con spazi', 'password-lunga', ''),
        fn() => $u->create('luca', 'corta', ''),
        fn() => $u->create('luca', 'password-lunga', 'non-una-email'),
        fn() => $u->create('luca', 'password-lunga', 'anna@esempio.it'),
        fn() => $u->create('luca', 'password-lunga', '', 'superadmin'),
        fn() => $u->setEmail('mario', 'anna@esempio.it'),
    ] as $i => $bad) {
        try {
            $bad();
            assertTrue(false, "richiesta {$i} accettata");
        } catch (UserException) {
        }
    }
    // Rilette dal file
    assertSame(['anna', 'mario'], array_keys((new Users($file))->all()));
});

test('utenti: bloccare, cambiare password o eliminare chiude le sessioni aperte', function () {
    $u = new Users(usersFile());
    $u->create('mario', 'password-lunga', '', 'admin');
    $u->create('anna', 'password-lunga', '');
    $session = $u->session('anna');
    $u->setBlocked('anna', true);
    assertSame(null, $u->session('anna'));
    assertSame('blocked', $u->check('anna', 'password-lunga'), 'bloccato: lo si dice solo con la password giusta');
    assertSame('invalid', $u->check('anna', 'sbagliata'));
    $u->setBlocked('anna', false);
    assertTrue($u->session('anna') !== $session, 'la sessione di prima non vale più');
    $before = $u->session('anna');
    $u->setPassword('anna', 'nuova-password-lunga');
    assertTrue($u->session('anna') !== $before);
    assertSame('ok', $u->check('anna', 'nuova-password-lunga'));
    $u->delete('anna');
    assertSame(null, $u->session('anna'));
    assertSame(null, $u->get('anna'));
});

test('utenti: resta sempre un admin attivo', function () {
    $u = new Users(usersFile());
    $u->create('mario', 'password-lunga', '', 'admin');
    $u->create('anna', 'password-lunga', '');
    foreach ([fn() => $u->delete('mario'), fn() => $u->setBlocked('mario', true), fn() => $u->setRole('mario', 'base')] as $bad) {
        try {
            $bad();
            assertTrue(false, 'l unico admin attivo non si toglie');
        } catch (UserException) {
        }
    }
    $u->setRole('anna', 'admin');
    $u->setBlocked('mario', true);
    assertSame(null, $u->session('mario'));
    try {
        $u->delete('anna');
        assertTrue(false, 'anna ora è l unico admin attivo');
    } catch (UserException) {
    }
    $u->delete('mario');
    assertSame(['anna'], array_keys($u->all()));
});

test('utenti: l account della 1.x diventa il primo admin e il file vecchio sparisce', function () {
    $legacy = tempFile('auth.php');
    (new Credentials($legacy))->save(['user' => 'Admin', 'password' => 'vecchia-password', 'email' => 'a@b.it', 'editor_url' => 'https://x.it/kris-admin/index.php']);
    $file = usersFile();
    $u = new Users($file, $legacy);
    assertSame('ok', $u->check('admin', 'vecchia-password'));
    assertSame('admin', $u->get('admin')['role']);
    assertSame('a@b.it', $u->get('admin')['email']);
    assertSame('https://x.it/kris-admin/index.php', $u->editorUrl());
    assertTrue(is_file($file) && !is_file($legacy), 'convertito una volta sola');
    assertNotContains('vecchia-password', (string) file_get_contents($file));
});

test('dopo cinque accessi sbagliati lo stesso indirizzo aspetta, gli altri no', function () {
    $file = sys_get_temp_dir() . '/kris-attempts-' . bin2hex(random_bytes(4)) . '.json';
    register_shutdown_function(fn() => @unlink($file));
    $t = new \Kris\Auth\LoginThrottle($file);
    $now = 1_000_000;
    for ($i = 0; $i < 4; $i++) $t->fail('1.2.3.4', $now + $i);
    assertSame(0, $t->waitSeconds('1.2.3.4', $now + 10));
    $t->fail('1.2.3.4', $now + 5);
    assertTrue($t->waitSeconds('1.2.3.4', $now + 10) > 0, 'il quinto errore blocca');
    assertSame(0, $t->waitSeconds('5.6.7.8', $now + 10), 'un altro indirizzo no');
    assertSame(0, $t->waitSeconds('1.2.3.4', $now + \Kris\Auth\LoginThrottle::WINDOW + 1), 'si sblocca da solo');
    assertTrue(!str_contains((string) file_get_contents($file), '1.2.3.4'), 'l indirizzo non si salva in chiaro');

    // Un accesso riuscito azzera gli errori di quell'indirizzo
    $t->succeed('1.2.3.4', $now + 11);
    assertSame(0, $t->waitSeconds('1.2.3.4', $now + 12));
});

test('troppi errori da molti indirizzi fermano l accesso per tutti', function () {
    $file = sys_get_temp_dir() . '/kris-attempts-' . bin2hex(random_bytes(4)) . '.json';
    register_shutdown_function(fn() => @unlink($file));
    $t = new \Kris\Auth\LoginThrottle($file);
    $now = 2_000_000;
    for ($i = 0; $i < \Kris\Auth\LoginThrottle::MAX_TOTAL; $i++) $t->fail("10.0.0.{$i}", $now);
    assertTrue($t->waitSeconds('192.168.1.1', $now + 1) > 0);
});

test('il codice di primo avvio si crea una volta, si verifica e si elimina', function () {
    $file = sys_get_temp_dir() . '/kris-setup-' . bin2hex(random_bytes(4)) . '/config/setup_code.php';
    register_shutdown_function(fn() => @unlink($file));
    $s = new \Kris\Auth\SetupCode($file);
    $code = $s->ensure();
    assertTrue(is_string($code) && preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) === 1, (string) $code);
    assertSame($code, $s->ensure(), 'non cambia a ogni visita');
    assertTrue(str_starts_with((string) file_get_contents($file), '<?php'), 'un file PHP: non si scarica');
    assertTrue($s->verify($code));
    assertTrue($s->verify(strtolower(str_replace('-', ' ', $code))), 'trattini, spazi e minuscole non contano');
    assertSame(false, $s->verify('AAAA-BBBB-CCCC'));
    assertSame(false, $s->verify(''));
    $s->remove();
    assertSame(false, $s->verify($code), 'creato l accesso il codice non vale piu');
});
