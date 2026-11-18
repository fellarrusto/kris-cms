<?php
declare(strict_types=1);

namespace Kris\Auth;

/**
 * Limite ai tentativi di accesso sbagliati, in config/auth_attempts.json
 * (privato, escluso da git come le credenziali).
 *
 * - Per indirizzo IP: dopo MAX_PER_IP errori in WINDOW secondi quell'IP
 *   aspetta che il più vecchio esca dalla finestra.
 * - In tutto: dopo MAX_TOTAL errori nella finestra l'accesso si ferma per
 *   tutti, contro chi prova da molti indirizzi. Si sblocca da solo.
 *
 * L'IP è REMOTE_ADDR: le intestazioni dei proxy le può scrivere chiunque.
 */
final class LoginThrottle
{
    public const WINDOW = 900;
    public const MAX_PER_IP = 5;
    public const MAX_TOTAL = 50;

    public function __construct(private string $file)
    {
    }

    /** Secondi da aspettare prima di poter riprovare, 0 se si può già. */
    public function waitSeconds(string $ip, ?int $now = null): int
    {
        $now ??= time();
        $failures = $this->recent($now);
        $mine = array_values(array_filter($failures, fn($f) => $f['ip'] === self::key($ip)));
        $wait = 0;
        if (count($mine) >= self::MAX_PER_IP) {
            $wait = max($wait, $mine[count($mine) - self::MAX_PER_IP]['at'] + self::WINDOW - $now);
        }
        if (count($failures) >= self::MAX_TOTAL) {
            $wait = max($wait, $failures[count($failures) - self::MAX_TOTAL]['at'] + self::WINDOW - $now);
        }
        return max(0, $wait);
    }

    public function fail(string $ip, ?int $now = null): void
    {
        $now ??= time();
        $failures = $this->recent($now);
        $failures[] = ['ip' => self::key($ip), 'at' => $now];
        // Tiene solo quanto serve al controllo totale
        $this->write(array_slice($failures, -self::MAX_TOTAL * 2));
    }

    /** Accesso riuscito: gli errori di quell'IP non contano più. */
    public function succeed(string $ip, ?int $now = null): void
    {
        $now ??= time();
        $key = self::key($ip);
        $this->write(array_values(array_filter($this->recent($now), fn($f) => $f['ip'] !== $key)));
    }

    /** L'IP non si salva in chiaro: basta riconoscerlo. */
    private static function key(string $ip): string
    {
        return substr(hash('sha256', 'kris-login|' . $ip), 0, 16);
    }

    /** @return list<array{ip: string, at: int}> errori dentro la finestra, dal più vecchio */
    private function recent(int $now): array
    {
        $data = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : [];
        $out = [];
        foreach (is_array($data) ? $data : [] as $f) {
            if (is_array($f) && is_string($f['ip'] ?? null) && is_int($f['at'] ?? null) && $f['at'] > $now - self::WINDOW) {
                $out[] = ['ip' => $f['ip'], 'at' => $f['at']];
            }
        }
        usort($out, fn($a, $b) => $a['at'] <=> $b['at']);
        return $out;
    }

    private function write(array $failures): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $tmp = $this->file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(array_values($failures)), LOCK_EX) !== false) {
            if (!@rename($tmp, $this->file)) @unlink($tmp);
        }
    }
}
