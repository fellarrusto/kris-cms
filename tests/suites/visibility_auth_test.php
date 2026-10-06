<?php
declare(strict_types=1);

/**
 * Elementi sospesi ("hidden": true) e recupero della password.
 */

use Kris\Auth\Credentials;
use Kris\Auth\PasswordReset;
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
    $data = [
        ['id' => 0, 'name' => 'progetti', 'data' => []],
        ['id' => 1, 'name' => 'progetti', 'hidden' => true, 'data' => []],
        ['id' => 0, 'name' => 'homepage', 'data' => [['name' => 'posts', 'type' => 'array', 'value' => [
            ['id' => 0, 'data' => []], ['id' => 1, 'hidden' => true, 'data' => []],
        ]]]],
    ];
    $entries = Seo::sitemapEntries($data, [
        ['page' => 'progetto', 'key' => 'progetti'],
        ['page' => 'post', 'key' => 'homepage', 'id' => 0, 'children' => 'posts'],
    ], ['progetto', 'post']);
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

test('il link di reset vale una volta sola e per 30 minuti', function () {
    $r = new PasswordReset(tempFile('reset.json'));
    $now = 1_000_000;
    $token = $r->create($now);
    assertSame(true, $r->isValid($token, $now + 60));
    assertSame(false, $r->isValid($token, $now + PasswordReset::TTL + 1), 'scaduto');
    assertSame(false, $r->isValid(str_repeat('a', 64), $now), 'token inventato');
    assertSame(true, $r->consume($token, $now + 60));
    assertSame(false, $r->consume($token, $now + 61), 'gia usato');
});

test('un nuovo link annulla il precedente', function () {
    $r = new PasswordReset(tempFile('reset.json'));
    $first = $r->create(1000);
    $second = $r->create(1010);
    assertSame(false, $r->isValid($first, 1020));
    assertSame(true, $r->isValid($second, 1020));
});

test('al massimo tre richieste l ora', function () {
    $r = new PasswordReset(tempFile('reset.json'));
    foreach ([0, 10, 20] as $t) $r->create(5000 + $t);
    assertSame(false, $r->canRequest(5030));
    assertSame(true, $r->canRequest(5000 + 3601));
});

test('sul disco resta solo l hash del token', function () {
    $file = tempFile('reset.json');
    $token = (new PasswordReset($file))->create();
    assertNotContains($token, (string) file_get_contents($file));
});
