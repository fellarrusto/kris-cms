<?php
declare(strict_types=1);

namespace Kris\Agent;

/**
 * POST di un JSON verso l'API di Kris Agent. https obbligatorio; http solo
 * verso localhost, per lo sviluppo. cURL se c'e, altrimenti gli stream di PHP.
 */
final class HttpPost
{
    private const TIMEOUT = 20;
    private const MAX_BYTES = 1024 * 1024;

    /**
     * @return array{status: int, json: ?array}
     * @throws AgentException indirizzo non ammesso o servizio irraggiungibile
     */
    public static function json(string $url, array $payload): array
    {
        if (!self::allowed($url)) {
            throw new AgentException("Indirizzo non ammesso per Kris Agent: {$url}. Serve https.");
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$status, $raw] = function_exists('curl_init') ? self::curl($url, $body) : self::stream($url, $body);
        $json = json_decode($raw, true);
        return ['status' => $status, 'json' => is_array($json) ? $json : null];
    }

    public static function allowed(string $url): bool
    {
        return preg_match('#^https://[^/\s]+(/\S*)?$#i', $url) === 1
            || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?(/\S*)?$#i', $url) === 1;
    }

    private static function curl(string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw) || $status === 0) {
            throw new AgentException('Kris Agent non risponde' . ($error !== '' ? ": {$error}" : '') . '. Riprova tra poco; se il problema resta, l\'hosting potrebbe bloccare le connessioni in uscita.');
        }
        return [$status, substr($raw, 0, self::MAX_BYTES)];
    }

    private static function stream(string $url, string $body): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
        }
        if ($raw === false || $status === 0) {
            throw new AgentException('Kris Agent non risponde. Riprova tra poco; se il problema resta, l\'hosting potrebbe bloccare le connessioni in uscita.');
        }
        return [$status, $raw];
    }
}
