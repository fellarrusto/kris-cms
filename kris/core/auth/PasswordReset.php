<?php
declare(strict_types=1);

namespace Kris\Auth;

/**
 * Link di reset della password: un token alla volta, monouso, valido 30
 * minuti. Sul disco resta solo il suo hash, in config/auth_reset.json, e al
 * massimo 3 richieste l'ora per non trasformare il modulo in un generatore
 * di email.
 */
final class PasswordReset
{
    public const TTL = 1800;
    public const MAX_PER_HOUR = 3;

    public function __construct(private string $file)
    {
    }

    /** Vero se si puo inviare un altro link adesso. */
    public function canRequest(?int $now = null): bool
    {
        $now ??= time();
        return count($this->recentRequests($now)) < self::MAX_PER_HOUR;
    }

    /** Nuovo token (da mettere nel link); sostituisce quello precedente. */
    public function create(?int $now = null): string
    {
        $now ??= time();
        $token = bin2hex(random_bytes(32));
        $requests = $this->recentRequests($now);
        $requests[] = $now;
        $this->write([
            'hash' => hash('sha256', $token),
            'expires' => $now + self::TTL,
            'requests' => $requests,
        ]);
        return $token;
    }

    public function isValid(string $token, ?int $now = null): bool
    {
        $now ??= time();
        $state = $this->read();
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1
            && is_string($state['hash'] ?? null)
            && (int) ($state['expires'] ?? 0) >= $now
            && hash_equals($state['hash'], hash('sha256', $token));
    }

    /** Usa il token: vero solo la prima volta e solo se valido. */
    public function consume(string $token, ?int $now = null): bool
    {
        if (!$this->isValid($token, $now)) {
            return false;
        }
        $state = $this->read();
        unset($state['hash'], $state['expires']);
        $this->write($state);
        return true;
    }

    private function recentRequests(int $now): array
    {
        $requests = $this->read()['requests'] ?? [];
        return array_values(array_filter(is_array($requests) ? $requests : [], fn($t) => is_int($t) && $t > $now - 3600));
    }

    private function read(): array
    {
        $state = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : null;
        return is_array($state) ? $state : [];
    }

    private function write(array $state): void
    {
        $tmp = $this->file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($state), LOCK_EX) === false || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException('Non riesco a scrivere in config/: controlla i permessi.');
        }
    }
}
