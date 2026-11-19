<?php
declare(strict_types=1);

namespace Kris\Agent;

/**
 * Indirizzi di caricamento sul dominio del sito (kris-agent.php?upload=<gettone>):
 * il Worker li chiede con una richiesta firmata (azione upload_slot), l'agente
 * o la persona ci manda il file direttamente, senza passare dal Worker (che in
 * alcuni ambienti non si raggiunge) né dalla conversazione.
 *
 * Il gettone è segreto, monouso, valido 15 minuti; sul disco ne resta solo lo
 * sha256 (data/agent_upload_slots.json). Il file passa dagli stessi controlli
 * dell'editor (Staging::upload, Staging::uploadFont) e va nello staging.
 */
final class UploadSlots
{
    public const TTL = 900;
    private const KEEP = 86400;

    public function __construct(private string $file)
    {
    }

    /** @return array{id: string, token: string, expires_at: string} */
    public function create(string $name, string $by, ?int $now = null): array
    {
        $now ??= time();
        $token = 'kss_' . bin2hex(random_bytes(24));
        $id = bin2hex(random_bytes(8));
        $this->change(function (array $slots) use ($id, $token, $name, $by, $now): array {
            $slots[$id] = ['hash' => hash('sha256', $token), 'name' => mb_substr($name, 0, 120), 'by' => mb_substr($by, 0, 120),
                'status' => 'waiting', 'url' => null, 'error' => null, 'expires' => $now + self::TTL, 'created' => $now];
            return $slots;
        }, $now);
        return ['id' => $id, 'token' => $token, 'expires_at' => gmdate('c', $now + self::TTL)];
    }

    /**
     * Prende il gettone per un caricamento: id e dati dell'indirizzo, o null se
     * non esiste, è scaduto o è già stato usato (o un caricamento è in corso).
     */
    public function begin(string $token, ?int $now = null): ?array
    {
        $now ??= time();
        if (preg_match('/^kss_[a-f0-9]{48}$/', $token) !== 1) return null;
        $hash = hash('sha256', $token);
        $found = null;
        $this->change(function (array $slots) use ($hash, $now, &$found): array {
            foreach ($slots as $id => $slot) {
                if (!hash_equals((string) $slot['hash'], $hash)) continue;
                if ($slot['status'] !== 'waiting' || $slot['expires'] < $now) return $slots;
                $slots[$id]['status'] = 'uploading';
                $found = ['id' => (string) $id] + $slot;
            }
            return $slots;
        }, $now);
        return $found;
    }

    /** Fine del caricamento: con l'indirizzo del file, o con l'errore (l'indirizzo torna libero). */
    public function finish(string $id, ?string $url, ?string $error = null): void
    {
        $this->change(function (array $slots) use ($id, $url, $error): array {
            if (!isset($slots[$id])) return $slots;
            $slots[$id] = ['status' => $url !== null ? 'done' : 'waiting', 'url' => $url, 'error' => $error] + $slots[$id];
            return $slots;
        });
    }

    /** @return array{slot_id: string, status: string, name: string, url: ?string, last_error: ?string, expires_at: string}|null */
    public function status(string $id, ?int $now = null): ?array
    {
        $now ??= time();
        $slot = $this->read()[$id] ?? null;
        if (!is_array($slot)) return null;
        $status = $slot['status'] === 'done' ? 'done' : ($slot['expires'] < $now ? 'expired' : $slot['status']);
        return ['slot_id' => $id, 'status' => $status, 'name' => (string) $slot['name'], 'url' => $slot['url'], 'last_error' => $slot['error'], 'expires_at' => gmdate('c', (int) $slot['expires'])];
    }

    private function read(): array
    {
        $data = json_decode((string) @file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    private function change(callable $fn, ?int $now = null): void
    {
        $now ??= time();
        if (!is_dir(dirname($this->file))) @mkdir(dirname($this->file), 0775, true);
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) throw new AgentException('Non riesco a scrivere data/agent_upload_slots.json.', 500);
        flock($handle, LOCK_EX);
        try {
            $slots = json_decode((string) stream_get_contents($handle), true);
            $slots = is_array($slots) ? $slots : [];
            // Gli indirizzi scaduti da più di un giorno non servono più nemmeno per lo stato
            $slots = array_filter($slots, fn($s) => is_array($s) && (int) ($s['expires'] ?? 0) > $now - self::KEEP);
            $slots = $fn($slots);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($slots, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
