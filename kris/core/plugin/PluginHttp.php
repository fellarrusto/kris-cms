<?php
declare(strict_types=1);

namespace Kris\Plugin;

/**
 * Le richieste in uscita di un plugin (IndexNow, API dei fornitori): solo
 * https, solo verso i domini dichiarati nel manifest, timeout breve,
 * risposta al massimo 1 MB, nessun redirect seguito.
 */
final class PluginHttp
{
    private const MAX_BYTES = 1048576;

    /** @param list<string> $domains */
    public function __construct(private array $domains, private int $timeout = 5)
    {
    }

    /** @return array{status: int, body: string} */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /** $body: stringa così com'è, o array mandato come JSON. */
    public function post(string $url, string|array $body, array $headers = []): array
    {
        if (is_array($body)) {
            $headers['Content-Type'] ??= 'application/json; charset=utf-8';
            $body = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->request('POST', $url, $body, $headers);
    }

    public function allowed(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['port'])
            && in_array(strtolower((string) ($parts['host'] ?? '')), $this->domains, true);
    }

    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        if (!$this->allowed($url)) throw new PluginException("Richiesta non ammessa: {$url} non è https o non è fra i domini dichiarati.");
        $lines = [];
        foreach ($headers as $k => $v) $lines[] = $k . ': ' . str_replace(["\r", "\n"], '', (string) $v);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $data = '';
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => $this->timeout, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$data): int {
                    $data .= $chunk;
                    return strlen($data) > self::MAX_BYTES ? 0 : strlen($chunk);
                },
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($ok === false) throw new PluginException("Richiesta a {$url} non riuscita: {$error}");
            return ['status' => $status, 'body' => $data];
        }
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body ?? '',
            'timeout' => $this->timeout, 'follow_location' => 0, 'ignore_errors' => true]]);
        $data = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        if ($data === false) throw new PluginException("Richiesta a {$url} non riuscita.");
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
        }
        return ['status' => $status, 'body' => $data];
    }
}
