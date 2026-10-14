<?php
declare(strict_types=1);

namespace Kris\Agent;

use Kris\Entity\JsonStore;
use Kris\Staging\Operations;
use Kris\Staging\Staging;
use Kris\Staging\StagingException;

/**
 * Riceve le richieste firmate di Kris Agent (kris-agent.php) e le esegue
 * sullo staging. Pubblicare resta all'admin del sito, dall'editor: nessuna
 * azione qui tocca i file pubblici.
 *
 * Una richiesta e valida solo se:
 *  - il sito e abbinato e l'agente e acceso;
 *  - sito e chiave sono quelli dell'abbinamento in corso;
 *  - l'ora e entro WINDOW secondi da quella del server;
 *  - il nonce non e gia stato visto (niente richieste ripetute);
 *  - la firma Ed25519 del Worker copre metodo, sito, ora, nonce e corpo.
 */
final class Server
{
    public const WINDOW = 300;
    private const MAX_BODY = 12 * 1024 * 1024; // un'immagine da 8 MB in base64, piu il resto

    private string $root;

    public function __construct(private AgentConfig $config, private Staging $staging, ?string $root = null)
    {
        $this->root = rtrim($root ?? KRIS_ROOT, '/\\');
    }

    /**
     * @param array<string, string> $headers intestazioni con nomi in minuscolo
     * @return array{status: int, body: array}
     */
    public function handle(string $method, array $headers, string $body, ?int $now = null): array
    {
        $now ??= time();
        try {
            if ($method !== 'POST') throw new AgentException('Usa POST.', 405);
            if (strlen($body) > self::MAX_BODY) throw new AgentException('Richiesta troppo grande.', 413);
            $config = $this->config->load();
            if ($config === null) throw new AgentException('Questo sito non è abbinato a Kris Agent.', 403);
            if (!function_exists('sodium_crypto_sign_verify_detached')) throw new AgentException('Sul sito manca l\'estensione sodium.', 500);
            $this->verify($config, $headers, $body, $now);
            if (empty($config['enabled'])) throw new AgentException('L\'admin del sito ha disattivato Kris Agent.', 403);

            $request = json_decode($body, true);
            if (!is_array($request) || !is_string($request['action'] ?? null)) throw new AgentException('Corpo non valido: serve un JSON con "action".', 400);
            return ['status' => 200, 'body' => ['ok' => true] + $this->dispatch($request)];
        } catch (AgentException $e) {
            return ['status' => $e->getCode() ?: 400, 'body' => ['ok' => false, 'error' => $e->getMessage()]];
        } catch (StagingException $e) {
            $details = $e->details();
            return ['status' => 422, 'body' => ['ok' => false, 'error' => $e->getMessage()] + ($details ? ['details' => $details] : [])];
        }
    }

    /** @throws AgentException */
    private function verify(array $config, array $headers, string $body, int $now): void
    {
        $site = $headers['x-kris-site'] ?? '';
        $key = $headers['x-kris-key'] ?? '';
        $timestamp = $headers['x-kris-timestamp'] ?? '';
        $nonce = $headers['x-kris-nonce'] ?? '';
        $signature = base64_decode($headers['x-kris-signature'] ?? '', true);

        if ($site !== $config['site_id'] || $key !== ($config['key_id'] ?? '')) {
            throw new AgentException('Richiesta per un altro abbinamento: abbina di nuovo il sito.', 401);
        }
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::WINDOW) {
            throw new AgentException('Richiesta scaduta o con l\'ora sbagliata.', 401);
        }
        if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            throw new AgentException('Nonce non valido.', 401);
        }
        $message = implode("\n", ['KRIS-AGENT-1', 'POST', $site, $timestamp, $nonce, hash('sha256', $body)]);
        $publicKey = base64_decode((string) $config['worker_public_key'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $publicKey === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new AgentException('Firma non valida.', 401);
        }
        // Il nonce si registra solo dopo la firma: chi non firma non puo riempire il registro
        $this->rememberNonce($nonce, $now);
    }

    /** @throws AgentException richiesta gia vista */
    private function rememberNonce(string $nonce, int $now): void
    {
        $store = new JsonStore($this->root . '/data/agent_nonces.json');
        $seen = $store->exists() ? $store->read([]) : [];
        $seen = array_filter($seen, fn($at) => is_int($at) && $now - $at <= 2 * self::WINDOW);
        if (isset($seen[$nonce])) {
            throw new AgentException('Richiesta già ricevuta.', 401);
        }
        $seen[$nonce] = $now;
        $store->write($seen);
    }

    /** @throws AgentException|StagingException */
    private function dispatch(array $r): array
    {
        $by = is_string($r['by'] ?? null) && $r['by'] !== '' ? mb_substr('agente: ' . $r['by'], 0, 120) : 'agente';
        $s = $this->staging;
        switch ($r['action']) {
            case 'ping':
                return ['kris_version' => \Kris\Update\SiteState::frameworkVersion(), 'staging' => $s->hasChanges()];

            case 'overview':
                return $this->overview();

            case 'read_file':
                return $s->read($this->string($r, 'file'));

            case 'write_file':
                return $s->write($this->string($r, 'file'), $this->string($r, 'content', true), $this->hashOrNull($r), $by);

            case 'edit_file':
                return $s->edit($this->string($r, 'file'), $this->string($r, 'old', true), $this->string($r, 'new', true), (string) $this->hashOrNull($r), $by);

            case 'get_entity':
                return ['entity' => $this->entity($s->state()['data'], $this->string($r, 'key'), (int) ($r['id'] ?? 0), (array) ($r['path'] ?? []))];

            case 'operate':
                if (!is_array($r['operation'] ?? null)) throw new AgentException('Serve "operation", un oggetto con "op".', 400);
                $done = $s->operate($r['operation'], $by);
                return ['operation' => $done, 'description' => Operations::describe($done)];

            case 'upload':
                $data = base64_decode($this->string($r, 'data', true), true);
                if ($data === false || $data === '') throw new AgentException('"data" deve essere il file in base64.', 400);
                $tmp = tempnam(sys_get_temp_dir(), 'kris-agent-');
                file_put_contents($tmp, $data);
                try {
                    return ['url' => $s->upload($tmp, basename($this->string($r, 'name')), $by)];
                } finally {
                    @unlink($tmp);
                }

            case 'status':
                return $this->status();

            case 'discard':
                $s->discard();
                return ['discarded' => true];

            default:
                throw new AgentException("Azione sconosciuta: {$r['action']}.", 404);
        }
    }

    /** Quello che serve all'agente per orientarsi: lingue, pagine, schema, contenuti, file del tema. */
    private function overview(): array
    {
        $state = $this->staging->state();
        $settingsFile = $this->root . '/data/cms_settings.json';
        $settings = is_file($settingsFile) ? (new JsonStore($settingsFile))->read([]) : [];
        $languages = !empty($settings['languages']) ? array_values($settings['languages']) : ['it', 'en'];

        $entities = [];
        foreach ($state['data'] as $entity) {
            $entities[$entity['name']][] = ['id' => $entity['id'], 'title' => $this->title($entity, $languages[0])] + (!empty($entity['hidden']) ? ['hidden' => true] : []);
        }
        $files = fn(string $pattern): array => array_values(array_unique(array_map(
            'basename',
            array_merge(glob("{$this->root}/{$pattern}") ?: [], glob($this->staging->dir() . "/{$pattern}") ?: [])
        )));
        return [
            'languages' => $languages,
            'pages' => $state['pages'],
            'model' => $state['model'],
            'entities' => $entities,
            'templates' => $files('template/*.html'),
            'stylesheets' => $files('assets/css/*.css'),
            'staging' => $this->status(),
        ];
    }

    private function status(): array
    {
        $s = $this->staging;
        return [
            'has_changes' => $s->hasChanges(),
            'files' => array_map(fn($c) => ['file' => $c['file'], 'kind' => $c['kind'], 'conflict' => $c['conflict'], 'sensitive' => $c['sensitive']], $s->changes()),
            'operations' => array_map(fn($o) => ['description' => $o['description'], 'conflict' => $o['conflict']], $s->rebuild()),
            'uploads' => array_map(fn($n) => "assets/uploads/{$n}", array_keys($s->manifest()['uploads'])),
            'template_problems' => $s->templateProblems(),
            // L'anteprima si apre solo con il login dell'editor: la pubblicazione la decide l'admin
            'preview' => 'index.php?kris_preview=1',
        ];
    }

    /** @throws AgentException */
    private function entity(array $data, string $key, int $id, array $path): array
    {
        foreach ($data as $entity) {
            if (($entity['name'] ?? null) === $key && (int) ($entity['id'] ?? -1) === $id) {
                $cur = $entity;
                for ($i = 0; $i + 1 < count($path); $i += 2) {
                    $next = null;
                    foreach ($cur['data'] ?? [] as $field) {
                        if (($field['name'] ?? null) === (string) $path[$i] && is_array($field['value'])) {
                            foreach ($field['value'] as $child) {
                                if ((int) ($child['id'] ?? -1) === (int) $path[$i + 1]) $next = $child;
                            }
                        }
                    }
                    if ($next === null) throw new AgentException("L'elemento {$path[$i]}/{$path[$i + 1]} non esiste.", 404);
                    $cur = $next;
                }
                return $cur;
            }
        }
        throw new AgentException("Il contenuto «{$key}» con id {$id} non esiste.", 404);
    }

    private function title(array $entity, string $lang): string
    {
        foreach ($entity['data'] ?? [] as $field) {
            if (in_array($field['type'] ?? '', ['text', 'plain'], true)) {
                $value = is_array($field['value']) ? ($field['value'][$lang] ?? reset($field['value'])) : $field['value'];
                if (is_string($value) && $value !== '') return mb_substr($value, 0, 80);
            }
        }
        return '';
    }

    /** @throws AgentException */
    private function string(array $r, string $key, bool $allowEmpty = false): string
    {
        if (!is_string($r[$key] ?? null) || (!$allowEmpty && $r[$key] === '')) {
            throw new AgentException("Manca \"{$key}\".", 400);
        }
        return $r[$key];
    }

    private function hashOrNull(array $r): ?string
    {
        return is_string($r['expected_hash'] ?? null) ? $r['expected_hash'] : null;
    }
}
