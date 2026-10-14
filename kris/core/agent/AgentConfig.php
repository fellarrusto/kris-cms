<?php
declare(strict_types=1);

namespace Kris\Agent;

/**
 * L'abbinamento del sito con Kris Agent, in config/agent.php (privato come
 * config/auth.php: contiene la chiave segreta con cui il sito firma).
 *
 *   enabled            l'admin del sito puo spegnere l'agente in ogni momento
 *   worker_url         indirizzo dell'API di Kris Agent
 *   site_id, key_id    come il Worker conosce questo sito e le chiavi in uso
 *   worker_public_key  verifica le richieste del Worker (Ed25519, base64)
 *   site_public_key, site_secret_key  le chiavi del sito (base64)
 */
final class AgentConfig
{
    private const FIELDS = ['enabled', 'worker_url', 'site_id', 'key_id', 'worker_public_key',
        'site_public_key', 'site_secret_key', 'paired_at', 'paired_by'];

    private string $file;

    public function __construct(?string $root = null)
    {
        $this->file = rtrim($root ?? KRIS_ROOT, '/\\') . '/config/agent.php';
    }

    /** L'abbinamento, o null se il sito non e abbinato. */
    public function load(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }
        $config = require $this->file;
        if (!is_array($config) || empty($config['site_id']) || empty($config['worker_public_key'])) {
            return null;
        }
        return $config + ['enabled' => true];
    }

    /** @throws AgentException */
    public function save(array $config): void
    {
        $config = array_intersect_key($config, array_flip(self::FIELDS));
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new AgentException('Non riesco a creare la cartella config/: controlla i permessi di scrittura.');
        }
        $php = "<?php\n// Abbinamento con Kris Agent. File privato: contiene la chiave segreta del sito.\nreturn "
            . var_export($config, true) . ";\n";
        $tmp = $this->file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new AgentException('Non riesco a scrivere config/agent.php: controlla i permessi di scrittura.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }
    }

    /** Accende o spegne l'agente senza perdere l'abbinamento. @throws AgentException */
    public function setEnabled(bool $enabled): void
    {
        $config = $this->load();
        if ($config === null) {
            throw new AgentException('Il sito non è abbinato.');
        }
        $config['enabled'] = $enabled;
        $this->save($config);
    }

    /** Scollega il sito: le richieste del Worker verranno rifiutate. */
    public function delete(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->file, true);
            }
        }
    }
}
