<?php
declare(strict_types=1);

/**
 * Kris Agent lato sito: abbinamento dall'editor e richieste firmate del Worker
 * su kris-agent.php. Il Worker e simulato con una coppia di chiavi Ed25519
 * creata qui; ogni test usa un sito temporaneo.
 */

use Kris\Agent\AgentConfig;
use Kris\Agent\AgentException;
use Kris\Agent\Pairing;
use Kris\Agent\Server;
use Kris\Staging\Staging;

if (!function_exists('sodium_crypto_sign_keypair')) {
    skipSuite('manca l\'estensione sodium');
    return;
}

/** Sito temporaneo abbinato a un Worker finto: restituisce [root, secret key del Worker]. */
function agentSite(bool $enabled = true): array
{
    $root = sys_get_temp_dir() . '/kris-agent-' . bin2hex(random_bytes(4));
    mkdir("{$root}/data", 0777, true);
    mkdir("{$root}/config", 0777, true);
    rcopy(KRIS_ROOT . '/template', "{$root}/template");
    copy(KRIS_TESTS . '/fixtures/k_data.json', "{$root}/data/k_data.json");
    copy(KRIS_TESTS . '/fixtures/k_model.json', "{$root}/data/k_model.json");
    copy(KRIS_ROOT . '/config/allowed_pages.json', "{$root}/config/allowed_pages.json");
    register_shutdown_function(fn() => rrmdir($root));

    $worker = sodium_crypto_sign_keypair();
    $site = sodium_crypto_sign_keypair();
    (new AgentConfig($root))->save([
        'enabled' => $enabled, 'worker_url' => 'https://api.example', 'site_id' => 'site-1', 'key_id' => 'key-1',
        'worker_public_key' => base64_encode(sodium_crypto_sign_publickey($worker)),
        'site_public_key' => base64_encode(sodium_crypto_sign_publickey($site)),
        'site_secret_key' => base64_encode(sodium_crypto_sign_secretkey($site)),
        'paired_at' => gmdate('c'), 'paired_by' => 'admin',
    ]);
    return [$root, sodium_crypto_sign_secretkey($worker)];
}

/** Una richiesta firmata come la manda il Worker, e la risposta del sito. */
function agentCall(string $root, string $workerSecret, array $payload, array $override = []): array
{
    $body = json_encode($payload);
    $headers = ['x-kris-site' => 'site-1', 'x-kris-key' => 'key-1', 'x-kris-timestamp' => (string) time(), 'x-kris-nonce' => bin2hex(random_bytes(16))];
    $headers = array_replace($headers, array_intersect_key($override, $headers));
    $message = implode("\n", ['KRIS-AGENT-1', 'POST', $headers['x-kris-site'], $headers['x-kris-timestamp'], $headers['x-kris-nonce'], hash('sha256', $body)]);
    $headers['x-kris-signature'] = $override['x-kris-signature'] ?? base64_encode(sodium_crypto_sign_detached($message, $workerSecret));
    $server = new Server(new AgentConfig($root), new Staging($root), $root);
    return $server->handle($override['method'] ?? 'POST', $headers, $override['body'] ?? $body);
}

// --- abbinamento -----------------------------------------------------------

function pairingCodeFor(string $api, string $site = 'site-1'): string
{
    return 'KRIS1-' . rtrim(strtr(base64_encode(json_encode(['u' => $api, 's' => $site, 'c' => str_repeat('ab', 32)])), '+/', '-_'), '=');
}

test('il codice di abbinamento si legge e uno storpiato viene rifiutato', function () {
    assertSame('site-9', Pairing::parse(pairingCodeFor('https://api.example', 'site-9'))['s']);
    foreach (['', 'KRIS1-xxx', 'altro', pairingCodeFor('http://evil.example')] as $code) {
        try {
            Pairing::parse($code);
            fail("codice accettato: {$code}");
        } catch (AgentException $e) {
            assertContains('non è valido', $e->getMessage());
        }
    }
});

test('l abbinamento manda chiave e indirizzo del sito e salva la chiave del Worker', function () {
    $root = sys_get_temp_dir() . '/kris-pair-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => rrmdir($root));
    $worker = sodium_crypto_sign_keypair();
    $sent = [];
    $post = function (string $url, array $payload) use (&$sent, $worker): array {
        $sent = ['url' => $url, 'payload' => $payload];
        return ['status' => 200, 'json' => ['site_id' => 'site-1', 'key_id' => 'key-7', 'worker_public_key' => base64_encode(sodium_crypto_sign_publickey($worker))]];
    };

    Pairing::pair(new AgentConfig($root), pairingCodeFor('https://api.example/'), 'https://www.sito.it/', 'https://www.sito.it/kris-agent.php', '1.4.0', 'admin', $post);

    assertSame('https://api.example/api/v1/pairing/complete', $sent['url']);
    assertSame('https://www.sito.it', $sent['payload']['site_url']);
    assertSame('1.4.0', $sent['payload']['kris_version']);
    assertSame(32, strlen(base64_decode($sent['payload']['site_public_key'])));
    $saved = (new AgentConfig($root))->load();
    assertSame('key-7', $saved['key_id']);
    assertSame(true, $saved['enabled']);
    assertSame($sent['payload']['site_public_key'], $saved['site_public_key']);
});

test('un codice rifiutato dal Worker non lascia un abbinamento a meta', function () {
    $root = sys_get_temp_dir() . '/kris-pair-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => rrmdir($root));
    try {
        Pairing::pair(new AgentConfig($root), pairingCodeFor('https://api.example'), 'https://www.sito.it', 'https://www.sito.it/kris-agent.php', '1.4.0', 'admin',
            fn() => ['status' => 401, 'json' => ['error' => 'UnauthorizedError']]);
        fail('l abbinamento doveva fallire');
    } catch (AgentException $e) {
        assertContains('scaduto', $e->getMessage());
    }
    assertSame(null, (new AgentConfig($root))->load());
});

// --- richieste firmate -----------------------------------------------------

test('una richiesta firmata dal Worker viene accettata', function () {
    [$root, $worker] = agentSite();
    $answer = agentCall($root, $worker, ['action' => 'ping']);
    assertSame(200, $answer['status']);
    assertSame(true, $answer['body']['ok']);
    assertSame(false, $answer['body']['staging']);
});

test('firma sbagliata, abbinamento diverso, ora lontana e nonce ripetuto vengono rifiutati', function () {
    [$root, $worker] = agentSite();
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    assertSame(401, agentCall($root, $other, ['action' => 'ping'])['status']);
    assertSame(401, agentCall($root, $worker, ['action' => 'ping'], ['x-kris-key' => 'key-vecchia'])['status']);
    assertSame(401, agentCall($root, $worker, ['action' => 'ping'], ['x-kris-site' => 'altro-sito'])['status']);
    assertSame(401, agentCall($root, $worker, ['action' => 'ping'], ['x-kris-timestamp' => (string) (time() - 3600)])['status']);
    assertSame(401, agentCall($root, $worker, ['action' => 'ping'], ['body' => '{"action":"discard"}'])['status'], 'corpo cambiato dopo la firma');

    $nonce = bin2hex(random_bytes(16));
    assertSame(200, agentCall($root, $worker, ['action' => 'ping'], ['x-kris-nonce' => $nonce])['status']);
    assertSame(401, agentCall($root, $worker, ['action' => 'ping'], ['x-kris-nonce' => $nonce])['status']);
});

test('agente spento o sito non abbinato rifiutano anche le richieste valide', function () {
    [$root, $worker] = agentSite(false);
    $answer = agentCall($root, $worker, ['action' => 'ping']);
    assertSame(403, $answer['status']);
    assertContains('disattivato', $answer['body']['error']);

    (new AgentConfig($root))->delete();
    assertSame(403, agentCall($root, $worker, ['action' => 'ping'])['status']);
});

test('l agente legge, modifica e carica solo nello staging', function () {
    [$root, $worker] = agentSite();
    $read = agentCall($root, $worker, ['action' => 'read_file', 'file' => 'template/homepage.html']);
    assertSame(200, $read['status']);
    $edit = agentCall($root, $worker, ['action' => 'edit_file', 'file' => 'template/homepage.html', 'old' => '</body>', 'new' => '<p>agente</p></body>',
        'expected_hash' => $read['body']['hash'], 'by' => 'mario@example.it']);
    assertSame(200, $edit['status']);
    $op = agentCall($root, $worker, ['action' => 'operate', 'operation' => ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'lang' => 'it', 'value' => 'Nuovo']]);
    assertSame(200, $op['status']);
    assertContains('page_title', $op['body']['description']);
    $png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $upload = agentCall($root, $worker, ['action' => 'upload', 'name' => 'foto.png', 'data' => $png]);
    assertSame(200, $upload['status']);

    assertNotContains('<p>agente</p>', (string) file_get_contents("{$root}/template/homepage.html"));
    $status = agentCall($root, $worker, ['action' => 'status'])['body'];
    assertSame(true, $status['has_changes']);
    assertSame('template/homepage.html', $status['files'][0]['file']);
    assertSame(1, count($status['operations']));
    assertSame([$upload['body']['url']], $status['uploads']);
    assertSame('agente: mario@example.it', (new Staging($root))->changes()[0]['changed_by']);

    $entity = agentCall($root, $worker, ['action' => 'get_entity', 'key' => 'homepage', 'id' => 0, 'path' => ['steps', 1]])['body']['entity'];
    assertSame(1, $entity['id']);
});

test('errori dello staging e azioni sconosciute tornano come risposte leggibili', function () {
    [$root, $worker] = agentSite();
    $denied = agentCall($root, $worker, ['action' => 'write_file', 'file' => 'kris/public.php', 'content' => 'x']);
    assertSame(422, $denied['status']);
    assertContains('non si può modificare', $denied['body']['error']);
    assertSame(404, agentCall($root, $worker, ['action' => 'promote'])['status'], 'pubblicare resta all admin');
    assertSame(400, agentCall($root, $worker, ['niente' => true])['status']);
    assertSame(405, agentCall($root, $worker, ['action' => 'ping'], ['method' => 'GET'])['status']);
});

test('la panoramica descrive lingue, pagine, schema, contenuti e file del tema', function () {
    [$root, $worker] = agentSite();
    $overview = agentCall($root, $worker, ['action' => 'overview'])['body'];
    assertSame(['it', 'en'], $overview['languages']);
    assertTrue(in_array('homepage', $overview['pages'], true));
    assertTrue(isset($overview['model']['homepage']));
    assertSame(0, $overview['entities']['homepage'][0]['id']);
    assertTrue(in_array('homepage.html', $overview['templates'], true));
});
