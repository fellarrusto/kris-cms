<?php
declare(strict_types=1);

namespace Kris\Update;

/**
 * Scaricamento di manifest e pacchetti.
 *
 * L'indirizzo del canale e configurabile, quindi non e fidato: si accettano
 * solo https e solo gli host in elenco, e i redirect vengono seguiti a mano
 * controllando ogni passaggio. Cosi quel campo non diventa un modo per far
 * interrogare al server indirizzi interni dell'hosting o file locali.
 * L'integrita di cio che arriva la garantisce la firma, non questo controllo.
 */
final class Http
{
    /** Host da cui si scarica: la repository su GitHub e i suoi file delle release. */
    public const ALLOWED_HOSTS = [
        'raw.githubusercontent.com',
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    private const MAX_REDIRECTS = 5;

    public function __construct(
        private array $allowedHosts = self::ALLOWED_HOSTS,
        private int $timeout = 30
    ) {
    }

    /** Controlla che l'indirizzo sia https e su un host ammesso. */
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['port'])) {
            throw new UpdateException("Indirizzo di aggiornamento non ammesso: serve un indirizzo https senza porta né credenziali.");
        }
        if (!in_array($host, $this->allowedHosts, true)) {
            throw new UpdateException("Indirizzo di aggiornamento non ammesso: l'host {$host} non è tra quelli consentiti.");
        }
    }

    /** Contenuto dell'indirizzo, al massimo $maxBytes byte. */
    public function get(string $url, int $maxBytes): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $this->assertAllowed($url);
            [$status, $location, $body] = function_exists('curl_init')
                ? $this->requestCurl($url, $maxBytes)
                : $this->requestStream($url, $maxBytes);

            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = $this->resolve($url, $location);
                continue;
            }
            if ($status === 404) {
                throw new UpdateException('Il file di aggiornamento non è stato trovato sul canale (404).');
            }
            if ($status !== 200) {
                throw new UpdateException("Il canale degli aggiornamenti ha risposto con un errore ({$status}). Riprova più tardi.");
            }
            return $body;
        }
        throw new UpdateException('Troppi reindirizzamenti dal canale degli aggiornamenti.');
    }

    /** @return array{0:int,1:?string,2:string} stato, Location, corpo */
    private function requestCurl(string $url, int $maxBytes): array
    {
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => 'Kris-Updater',
            CURLOPT_HEADER         => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0; // interrompe il trasferimento
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        $errno = curl_errno($ch);
        unset($ch); // da PHP 8 l'handle si chiude da solo: curl_close e deprecata

        if ($tooBig) {
            throw new UpdateException('Il file scaricato supera la dimensione massima prevista.');
        }
        // 35, 60, 77: la connessione c'e, ma PHP non riesce a verificare il
        // certificato. La verifica non si disattiva: si dice cosa manca.
        if ($ok === false && in_array($errno, [35, 60, 77], true)) {
            throw new UpdateException(
                "PHP su questo hosting non riesce a verificare il certificato HTTPS del canale degli aggiornamenti "
                . "(manca l'elenco dei certificati, impostazione curl.cainfo). Chiedi all'hosting di configurarlo, "
                . 'oppure carica il pacchetto a mano.'
            );
        }
        if ($ok === false && $errno !== 0) {
            throw new UpdateException(
                "Non riesco a contattare il canale degli aggiornamenti: l'hosting potrebbe bloccare le connessioni in uscita. "
                . 'Puoi caricare il pacchetto a mano.'
            );
        }
        return [$status, is_string($location) ? $location : null, $body];
    }

    /** @return array{0:int,1:?string,2:string} */
    private function requestStream(string $url, int $maxBytes): array
    {
        if (!ini_get('allow_url_fopen')) {
            throw new UpdateException(
                "Questo hosting non permette di scaricare file da PHP (né cURL né allow_url_fopen). Puoi caricare il pacchetto a mano."
            );
        }
        $context = stream_context_create([
            'http' => [
                'follow_location' => 0,
                'ignore_errors'   => true,
                'timeout'         => $this->timeout,
                'user_agent'      => 'Kris-Updater',
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw new UpdateException(
                "Non riesco a contattare il canale degli aggiornamenti: l'hosting potrebbe bloccare le connessioni in uscita. "
                . 'Puoi caricare il pacchetto a mano.'
            );
        }
        $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
        $body = (string) stream_get_contents($handle, $maxBytes + 1);
        fclose($handle);
        if (strlen($body) > $maxBytes) {
            throw new UpdateException('Il file scaricato supera la dimensione massima prevista.');
        }

        $status = 0;
        $location = null;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $location = null; // conta solo l'ultima risposta
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }
        return [$status, $location, $body];
    }

    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        if (str_starts_with($location, '/')) {
            return $p['scheme'] . '://' . $p['host'] . $location;
        }
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        return $p['scheme'] . '://' . $p['host'] . $dir . '/' . $location;
    }
}
