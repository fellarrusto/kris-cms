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
    copy(KRIS_TESTS . '/fixtures/allowed_pages.json', "{$root}/config/allowed_pages.json");
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
    assertSame('homepage', $overview['pages']['homepage']['type']);
    assertSame('list', array_column($overview['types']['homepage']['fields'], 'type', 'name')['steps']);
    assertSame(0, $overview['entities']['homepage'][0]['id']);
    assertTrue(in_array('homepage.html', $overview['templates'], true));
});

// --- richieste del sito al Worker ------------------------------------------

/** Un Worker finto che verifica la firma del sito con la chiave pubblica salvata all'abbinamento. */
function fakeWorker(string $root, callable $answer, array &$seen = []): callable
{
    return function (string $url, string $body, array $headers) use ($root, $answer, &$seen): array {
        $config = (new AgentConfig($root))->load();
        $message = implode("\n", ['KRIS-AGENT-1', 'POST', $headers['X-Kris-Site'], $headers['X-Kris-Timestamp'], $headers['X-Kris-Nonce'], hash('sha256', $body)]);
        $ok = sodium_crypto_sign_verify_detached((string) base64_decode($headers['X-Kris-Signature']), $message, (string) base64_decode($config['site_public_key']));
        $seen[] = ['url' => $url, 'body' => json_decode($body, true), 'headers' => $headers];
        return $ok ? $answer($url, json_decode($body, true)) : ['status' => 401, 'json' => ['error' => 'UnauthorizedError']];
    };
}

test('il sito chiede gli utenti e decide con richieste firmate dalla sua chiave', function () {
    [$root] = agentSite();
    $seen = [];
    $client = new \Kris\Agent\WorkerClient(new AgentConfig($root), fakeWorker($root, fn($url, $body) => ['status' => 200, 'json' => ['users' => [['user_id' => 'u1', 'reason' => 'grant_pending']]]], $seen));

    assertSame('u1', $client->users()[0]['user_id']);
    $client->decide('u1', 'approved');

    assertSame('https://api.example/api/v1/site-api/users', $seen[0]['url']);
    assertSame(['user_id' => 'u1', 'status' => 'approved'], $seen[1]['body']);
    assertSame('key-1', $seen[1]['headers']['X-Kris-Key']);
    assertTrue($seen[0]['headers']['X-Kris-Nonce'] !== $seen[1]['headers']['X-Kris-Nonce']);
});

test('reimpostare l accesso salva le chiavi nuove solo se il Worker conferma', function () {
    [$root] = agentSite();
    $before = (new AgentConfig($root))->load();
    $newWorker = sodium_crypto_sign_keypair();
    $sent = [];
    $client = new \Kris\Agent\WorkerClient(new AgentConfig($root), fakeWorker($root, fn($url, $body) => ['status' => 200, 'json' => [
        'key_id' => 'key-2', 'worker_public_key' => base64_encode(sodium_crypto_sign_publickey($newWorker)), 'access_generation' => 1,
    ]], $sent));

    $client->resetAccess();

    $after = (new AgentConfig($root))->load();
    assertSame('key-2', $after['key_id']);
    assertSame($sent[0]['body']['site_public_key'], $after['site_public_key']);
    assertTrue($after['site_public_key'] !== $before['site_public_key'] && $after['site_secret_key'] !== $before['site_secret_key']);

    $failing = new \Kris\Agent\WorkerClient(new AgentConfig($root), fn() => ['status' => 500, 'json' => ['message' => 'errore']]);
    try {
        $failing->resetAccess();
        fail('il reset doveva fallire');
    } catch (AgentException $e) {
        assertContains('rifiutato', $e->getMessage());
    }
    assertSame('key-2', (new AgentConfig($root))->load()['key_id'], 'chiavi invariate se il Worker non conferma');
});

test('un abbinamento non piu riconosciuto chiede di abbinare di nuovo', function () {
    [$root] = agentSite();
    $client = new \Kris\Agent\WorkerClient(new AgentConfig($root), fn() => ['status' => 401, 'json' => null]);
    try {
        $client->users();
        fail('doveva fallire');
    } catch (AgentException $e) {
        assertContains('abbinalo di nuovo', $e->getMessage());
    }
});

test('l agente registra piu operazioni in una chiamata, toglie un caricamento e riceve hash attuale e avvisi', function () {
    [$root, $worker] = agentSite();
    $batch = agentCall($root, $worker, ['action' => 'operate_batch', 'operations' => [
        ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'page_title', 'value' => 'Solo italiano'],
        ['op' => 'add_item', 'key' => 'homepage', 'id' => 0, 'array' => 'steps', 'fields' => ['title' => ['it' => 'Passo', 'en' => 'Step']]],
    ]]);
    assertSame(200, $batch['status']);
    assertSame(2, count($batch['body']['operations']));
    assertContains('lingua en', $batch['body']['warnings'][0]);
    assertSame(400, agentCall($root, $worker, ['action' => 'operate_batch', 'operations' => []])['status']);

    $png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $url = agentCall($root, $worker, ['action' => 'upload', 'name' => 'foto.png', 'data' => $png])['body']['url'];
    assertSame(200, agentCall($root, $worker, ['action' => 'delete_upload', 'url' => $url])['status']);
    assertSame([], agentCall($root, $worker, ['action' => 'status'])['body']['uploads']);

    $read = agentCall($root, $worker, ['action' => 'read_file', 'file' => 'template/homepage.html'])['body'];
    agentCall($root, $worker, ['action' => 'edit_file', 'file' => 'template/homepage.html', 'old' => '</body>', 'new' => '<p>a</p></body>', 'expected_hash' => $read['hash']]);
    $stale = agentCall($root, $worker, ['action' => 'edit_file', 'file' => 'template/homepage.html', 'old' => '<p>a</p>', 'new' => '<p>b</p>', 'expected_hash' => $read['hash']]);
    assertSame(422, $stale['status']);
    $retry = agentCall($root, $worker, ['action' => 'edit_file', 'file' => 'template/homepage.html', 'old' => '<p>a</p>', 'new' => '<p>b</p>',
        'expected_hash' => $stale['body']['details']['current_hash']]);
    assertSame(200, $retry['status'], 'con l hash attuale si riparte senza rileggere');
    assertTrue(is_array(agentCall($root, $worker, ['action' => 'status'])['body']['warnings']));
});

test('read_file legge a pezzi, cerca e da la sola impronta; edit_file senza impronta', function () {
    [$root, $worker] = agentSite();
    $file = 'assets/css/prova.css';
    $css = implode("\n", array_map(fn($i) => ".r{$i} { color: red; }", range(1, 1000)));
    agentCall($root, $worker, ['action' => 'write_file', 'file' => $file, 'content' => $css]);

    $whole = agentCall($root, $worker, ['action' => 'read_file', 'file' => $file])['body'];
    assertSame(1000, $whole['total_lines']);
    $part = agentCall($root, $worker, ['action' => 'read_file', 'file' => $file, 'from_line' => 10, 'lines' => 3])['body'];
    assertSame(".r10 { color: red; }\n.r11 { color: red; }\n.r12 { color: red; }", $part['content']);
    assertSame([10, 12], [$part['from_line'], $part['to_line']]);
    assertSame($whole['hash'], $part['hash'], 'l impronta e quella del file intero');
    $capped = agentCall($root, $worker, ['action' => 'read_file', 'file' => $file, 'from_line' => 1, 'lines' => 5000])['body'];
    assertSame(400, $capped['to_line']);
    $found = agentCall($root, $worker, ['action' => 'read_file', 'file' => $file, 'search' => '.r999 '])['body'];
    assertSame([['line' => 999, 'text' => '.r999 { color: red; }']], $found['matches']);
    $hash = agentCall($root, $worker, ['action' => 'read_file', 'file' => $file, 'hash_only' => true])['body'];
    assertTrue(!isset($hash['content']) && $hash['hash'] === $whole['hash']);

    // Testo unico: si modifica senza impronta
    $edit = agentCall($root, $worker, ['action' => 'edit_file', 'file' => $file, 'old' => '.r500 { color: red; }', 'new' => '.r500 { color: blue; }']);
    assertSame(200, $edit['status'], json_encode($edit['body']));
    // Testo ripetuto: rifiutato anche senza impronta
    $twice = agentCall($root, $worker, ['action' => 'edit_file', 'file' => $file, 'old' => 'color: red;', 'new' => 'color: green;']);
    assertTrue($twice['status'] >= 400, json_encode($twice['body']));
    assertContains('volte', (string) ($twice['body']['error'] ?? ''));
});

test('search_theme cerca in tutti i file del tema, con lo staging sopra il sito', function () {
    [$root, $worker] = agentSite();
    agentCall($root, $worker, ['action' => 'write_file', 'file' => 'template/nuova.html', 'content' => "<p>uno</p>\n<p>UNICO-NELLO-STAGING</p>"]);
    $r = agentCall($root, $worker, ['action' => 'search_theme', 'query' => 'unico-nello-staging'])['body'];
    assertSame([['file' => 'template/nuova.html', 'line' => 2, 'text' => '<p>UNICO-NELLO-STAGING</p>']], $r['matches']);
    $nav = agentCall($root, $worker, ['action' => 'search_theme', 'query' => 'k-component="navbar"'])['body'];
    assertTrue(count($nav['matches']) >= 3, 'i template del sito');
});

test('site_config mostra pagine, seo, robots e sitemap con le modifiche in attesa', function () {
    [$root, $worker] = agentSite();
    file_put_contents("{$root}/robots.txt", "User-agent: *\nDisallow: /editor/\n");
    agentCall($root, $worker, ['action' => 'write_file', 'file' => 'template/lavagna.html', 'content' => '<p>{{page_title}}</p>']);
    agentCall($root, $worker, ['action' => 'operate', 'operation' => ['op' => 'register_page', 'page' => 'lavagna', 'sitemap' => false]]);
    $c = agentCall($root, $worker, ['action' => 'site_config'])['body'];
    assertSame(false, $c['pages']['lavagna']['sitemap']);
    assertContains('Disallow: /editor/', (string) $c['robots_txt']);
    assertContains('<urlset', $c['sitemap_xml']);
    assertNotContains('page=lavagna', $c['sitemap_xml']);
});

test('render_page restituisce l HTML finito di una pagina con le modifiche in attesa', function () {
    $sandbox = projectSandbox();
    $s = new Staging($sandbox);
    $file = 'template/homepage.html';
    $original = (string) file_get_contents("{$sandbox}/{$file}");
    $s->write($file, str_replace('</body>', '<p>SOLO-IN-STAGING</p></body>', $original), Staging::hash($original), 'test');
    try {
        $env = getenv();
        $env['KRIS_QUERY'] = http_build_query(['page' => 'homepage', 'ln' => 'en']);
        $proc = proc_open(phpCommand(KRIS_TESTS . '/harness/agent_render.php'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $sandbox, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        foreach ($pipes as $p) fclose($p);
        proc_close($proc);
        $page = json_decode((string) $out, true);
        assertTrue(is_array($page), $out . $err);
        assertSame(200, $page['status']);
        assertSame('en', $page['lang']);
        assertContains('SOLO-IN-STAGING', $page['html']);
        assertContains('rel="canonical"', $page['html'], 'quello che Kris aggiunge da solo');
        assertContains('hreflang="x-default"', $page['html']);
        assertNotContains('kris-preview-bar', $page['html'], 'senza la barra dell anteprima');
    } finally {
        $s->discard();
    }
});

test('indirizzo di caricamento sul dominio del sito: monouso, nello staging, stato per il Worker', function () {
    [$root, $worker] = agentSite();
    $_SERVER['HTTP_HOST'] = 'www.sito.it';
    $slot = agentCall($root, $worker, ['action' => 'upload_slot', 'name' => 'squadra.png', 'by' => 'mario'])['body'];
    assertTrue(str_starts_with($slot['upload_url'], 'http://www.sito.it/kris-agent.php?upload=kss_'), $slot['upload_url']);
    $token = substr($slot['upload_url'], strpos($slot['upload_url'], '=') + 1);
    assertSame('waiting', agentCall($root, $worker, ['action' => 'upload_slot_status', 'slot_id' => $slot['slot_id']])['body']['status']);

    $server = new Server(new AgentConfig($root), new Staging($root), $root);
    $bad = tempnam(sys_get_temp_dir(), 'k');
    file_put_contents($bad, 'non un\'immagine');
    assertSame(422, $server->receiveUpload($token, $bad, null)['status'], 'un file rifiutato');
    assertSame('waiting', agentCall($root, $worker, ['action' => 'upload_slot_status', 'slot_id' => $slot['slot_id']])['body']['status'], 'resta libero dopo un rifiuto');

    $png = tempnam(sys_get_temp_dir(), 'k');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $ok = $server->receiveUpload($token, $png, null);
    assertSame(200, $ok['status']);
    assertTrue(str_starts_with($ok['body']['url'], 'assets/uploads/') && str_ends_with($ok['body']['url'], '.png'));
    $done = agentCall($root, $worker, ['action' => 'upload_slot_status', 'slot_id' => $slot['slot_id']])['body'];
    assertSame(['done', $ok['body']['url']], [$done['status'], $done['url']]);
    assertSame(404, $server->receiveUpload($token, $png, null)['status'], 'monouso');
    assertSame(404, $server->receiveUpload('kss_' . str_repeat('0', 48), $png, null)['status']);
    assertSame([$ok['body']['url']], agentCall($root, $worker, ['action' => 'status'])['body']['uploads']);

    // Nessun contenuto lo usa ancora: è fra i file non usati, finché un campo non lo prende
    assertSame([$ok['body']['url']], array_column(agentCall($root, $worker, ['action' => 'unused_uploads'])['body']['files'], 'url'));
    agentCall($root, $worker, ['action' => 'operate', 'operation' => ['op' => 'set_field', 'key' => 'homepage', 'id' => 0, 'field' => 'logo', 'value' => $ok['body']['url']]]);
    assertSame([], agentCall($root, $worker, ['action' => 'unused_uploads'])['body']['files']);
    @unlink($bad);
    @unlink($png);
    unset($_SERVER['HTTP_HOST']);
});

test('con Kris Agent spento l indirizzo di caricamento non accetta file', function () {
    [$root, $worker] = agentSite();
    $slot = agentCall($root, $worker, ['action' => 'upload_slot', 'name' => 'x.png'])['body'];
    $config = new AgentConfig($root);
    $config->save(['enabled' => false] + $config->load());
    $token = substr($slot['upload_url'], strpos($slot['upload_url'], '=') + 1);
    assertSame(403, (new Server($config, new Staging($root), $root))->receiveUpload($token, __FILE__, 'x.png')['status']);
});
