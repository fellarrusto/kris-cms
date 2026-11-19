<?php
declare(strict_types=1);

namespace Kris\Auth;

/**
 * Link di reset della password, uno per utente: monouso, valido 30 minuti.
 * Sul disco resta solo il suo hash, in config/auth_reset.json, e al massimo
 * 3 richieste l'ora per utente, per non trasformare il modulo in un
 * generatore di email.
 */
final class PasswordReset
{
    public const TTL = 1800;
    public const MAX_PER_HOUR = 3;

    public function __construct(private string $file)
    {
    }

    /** Vero se si può inviare un altro link a questo utente adesso. */
    public function canRequest(string $user, ?int $now = null): bool
    {
        $now ??= time();
        return count($this->recentRequests($this->read(), $user, $now)) < self::MAX_PER_HOUR;
    }

    /** Nuovo token per l'utente (da mettere nel link); sostituisce il suo precedente. */
    public function create(string $user, ?int $now = null): string
    {
        $now ??= time();
        $token = bin2hex(random_bytes(32));
        $state = $this->read();
        $state['requests'][$user] = [...$this->recentRequests($state, $user, $now), $now];
        $state['tokens'][$user] = ['hash' => hash('sha256', $token), 'expires' => $now + self::TTL];
        $this->write($state);
        return $token;
    }

    /** L'utente del token, se il token è valido; altrimenti null. */
    public function userOf(string $token, ?int $now = null): ?string
    {
        $now ??= time();
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) return null;
        $hash = hash('sha256', $token);
        foreach ($this->read()['tokens'] as $user => $t) {
            if (is_array($t) && is_string($t['hash'] ?? null) && (int) ($t['expires'] ?? 0) >= $now && hash_equals($t['hash'], $hash)) return (string) $user;
        }
        return null;
    }

    /** Usa il token: restituisce l'utente solo la prima volta e solo se valido. */
    public function consume(string $token, ?int $now = null): ?string
    {
        $user = $this->userOf($token, $now);
        if ($user === null) return null;
        $state = $this->read();
        unset($state['tokens'][$user]);
        $this->write($state);
        return $user;
    }

    private function recentRequests(array $state, string $user, int $now): array
    {
        $requests = $state['requests'][$user] ?? [];
        return array_values(array_filter(is_array($requests) ? $requests : [], fn($t) => is_int($t) && $t > $now - 3600));
    }

    private function read(): array
    {
        $state = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : null;
        $state = is_array($state) ? $state : [];
        // Il formato della 1.x (un solo token) non vale più: i suoi link scadono comunque in 30 minuti
        return ['tokens' => is_array($state['tokens'] ?? null) ? $state['tokens'] : [], 'requests' => is_array($state['requests'] ?? null) && !array_is_list($state['requests']) ? $state['requests'] : []];
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
