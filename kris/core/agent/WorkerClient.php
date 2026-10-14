<?php
declare(strict_types=1);

namespace Kris\Agent;

/**
 * Richieste del sito a Kris Agent, firmate con la chiave del sito creata
 * all'abbinamento (stesso protocollo delle richieste del Worker al sito):
 * chi puo usare l'agente, approvazioni e revoche, reset dell'accesso.
 */
final class WorkerClient
{
    /** @var callable(string, string, array): array{status: int, json: ?array} */
    private $send;

    /** @param callable|null $send per i test; di norma HttpPost::send */
    public function __construct(private AgentConfig $config, ?callable $send = null)
    {
        $this->send = $send ?? [HttpPost::class, 'send'];
    }

    /** @return list<array> gli utenti con il loro stato @throws AgentException */
    public function users(): array
    {
        return (array) ($this->call('/api/v1/site-api/users', [])['users'] ?? []);
    }

    /** @param 'approved'|'revoked' $status @return list<array> @throws AgentException */
    public function decide(string $userId, string $status): array
    {
        return (array) ($this->call('/api/v1/site-api/grants', ['user_id' => $userId, 'status' => $status])['users'] ?? []);
    }

    /**
     * Tutti tornano in attesa di approvazione e si cambiano le chiavi di
     * entrambe le parti: la nuova chiave pubblica del sito viaggia firmata con
     * quella vecchia. Le chiavi nuove si salvano solo se il Worker conferma.
     *
     * @throws AgentException
     */
    public function resetAccess(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $answer = $this->call('/api/v1/site-api/reset-access', ['site_public_key' => $public]);
        $workerKey = base64_decode((string) ($answer['worker_public_key'] ?? ''), true);
        if (!is_string($answer['key_id'] ?? null) || $workerKey === false || strlen($workerKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new AgentException('Kris Agent ha risposto in un formato inatteso: abbina di nuovo il sito.');
        }
        $config = (array) $this->config->load();
        $config['key_id'] = $answer['key_id'];
        $config['worker_public_key'] = $answer['worker_public_key'];
        $config['site_public_key'] = $public;
        $config['site_secret_key'] = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $this->config->save($config);
    }

    /** @throws AgentException */
    private function call(string $path, array $payload): array
    {
        $config = $this->config->load();
        if ($config === null) {
            throw new AgentException('Il sito non è abbinato a Kris Agent.');
        }
        $secret = base64_decode((string) ($config['site_secret_key'] ?? ''), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new AgentException('Le chiavi del sito non sono leggibili: scollega e abbina di nuovo il sito.');
        }
        $body = (string) json_encode((object) $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $message = implode("\n", ['KRIS-AGENT-1', 'POST', (string) $config['site_id'], $timestamp, $nonce, hash('sha256', $body)]);

        $answer = ($this->send)(rtrim((string) $config['worker_url'], '/') . $path, $body, [
            'X-Kris-Site' => (string) $config['site_id'],
            'X-Kris-Key' => (string) $config['key_id'],
            'X-Kris-Timestamp' => $timestamp,
            'X-Kris-Nonce' => $nonce,
            'X-Kris-Signature' => base64_encode(sodium_crypto_sign_detached($message, $secret)),
        ]);
        $status = (int) ($answer['status'] ?? 0);
        if ($status === 401) {
            throw new AgentException('Kris Agent non riconosce più questo abbinamento: scollega il sito e abbinalo di nuovo con un codice nuovo.');
        }
        if ($status !== 200 || !is_array($answer['json'] ?? null)) {
            $detail = is_string($answer['json']['message'] ?? null) ? ': ' . $answer['json']['message'] : " (HTTP {$status})";
            throw new AgentException('Kris Agent ha rifiutato la richiesta' . $detail . '.');
        }
        return $answer['json'];
    }
}
