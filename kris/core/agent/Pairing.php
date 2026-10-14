<?php
declare(strict_types=1);

namespace Kris\Agent;

/**
 * Abbinamento con Kris Agent dall'editor: l'admin incolla il codice generato
 * nel pannello di Kris Agent. Il codice contiene l'indirizzo dell'API, l'id
 * del sito e un segreto monouso; il sito crea le proprie chiavi Ed25519,
 * manda la pubblica e riceve quella del Worker.
 */
final class Pairing
{
    private const PREFIX = 'KRIS1-';

    /**
     * @param string $siteUrl indirizzo pubblico del sito, come registrato nel pannello
     * @param string $endpoint indirizzo di kris-agent.php
     * @param callable(string, array): array{status: int, json: ?array} $post per i test; di norma HttpPost::json
     * @throws AgentException
     */
    public static function pair(AgentConfig $config, string $code, string $siteUrl, string $endpoint, string $version, string $by, ?callable $post = null): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new AgentException('Per Kris Agent serve l\'estensione PHP sodium: chiedi all\'hosting di attivarla.');
        }
        $parsed = self::parse($code);
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $answer = ($post ?? [HttpPost::class, 'json'])(rtrim($parsed['u'], '/') . '/api/v1/pairing/complete', [
            'code' => trim($code),
            'site_url' => rtrim($siteUrl, '/'),
            'endpoint' => $endpoint,
            'site_public_key' => $public,
            'kris_version' => $version,
        ]);
        $json = $answer['json'] ?? null;
        if (($answer['status'] ?? 0) !== 200 || !is_array($json)) {
            throw new AgentException(self::refusal((int) ($answer['status'] ?? 0), $json));
        }
        $workerKey = base64_decode((string) ($json['worker_public_key'] ?? ''), true);
        if ($workerKey === false || strlen($workerKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || ($json['site_id'] ?? null) !== $parsed['s']) {
            throw new AgentException('Kris Agent ha risposto in un formato inatteso: l\'abbinamento non è avvenuto.');
        }

        $saved = [
            'enabled' => true,
            'worker_url' => rtrim($parsed['u'], '/'),
            'site_id' => $parsed['s'],
            'key_id' => (string) $json['key_id'],
            'worker_public_key' => (string) $json['worker_public_key'],
            'site_public_key' => $public,
            'site_secret_key' => $secret,
            'paired_at' => gmdate('c'),
            'paired_by' => $by,
        ];
        $config->save($saved);
        return $saved;
    }

    /** @return array{u: string, s: string, c: string} @throws AgentException */
    public static function parse(string $code): array
    {
        $code = trim($code);
        $payload = null;
        if (str_starts_with($code, self::PREFIX)) {
            $raw = base64_decode(strtr(substr($code, strlen(self::PREFIX)), '-_', '+/'), true);
            $payload = $raw === false ? null : json_decode($raw, true);
        }
        if (!is_array($payload) || !is_string($payload['u'] ?? null) || !is_string($payload['s'] ?? null) || !is_string($payload['c'] ?? null)
            || !HttpPost::allowed($payload['u'])) {
            throw new AgentException('Il codice non è valido: copialo di nuovo per intero dal pannello di Kris Agent.');
        }
        return $payload;
    }

    private static function refusal(int $status, ?array $json): string
    {
        return match ($status) {
            401 => 'Il codice è scaduto o è già stato usato: generane uno nuovo dal pannello di Kris Agent.',
            403 => 'Il codice è per un altro indirizzo: ' . (string) ($json['message'] ?? '') . '. Controlla l\'indirizzo del sito nel pannello.',
            default => 'Kris Agent ha rifiutato l\'abbinamento' . (isset($json['message']) ? ': ' . $json['message'] : " (HTTP {$status})") . '.',
        };
    }
}
