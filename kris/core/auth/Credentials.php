<?php
declare(strict_types=1);

namespace Kris\Auth;

/**
 * Credenziali del pannello in config/auth.php (escluso da git):
 *
 *   user        nome utente
 *   hash        password_hash della password
 *   email       email dell'admin, per il recupero della password (opzionale)
 *   editor_url  indirizzo dell'editor, salvato quando un admin autenticato
 *               imposta l'email: i link di reset partono da qui e non
 *               dall'host della richiesta, che chiunque puo falsificare
 */
final class Credentials
{
    public const MIN_PASSWORD = 10;

    public function __construct(private string $file)
    {
    }

    public function load(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }
        $config = require $this->file;
        if (!is_array($config) || empty($config['user']) || empty($config['hash'])) {
            return null;
        }
        return $config + ['email' => '', 'editor_url' => ''];
    }

    public function verify(string $user, string $pass): bool
    {
        $config = $this->load();
        if ($config === null) {
            return false;
        }
        // hash_equals non rivela lo username carattere per carattere.
        $userOk = hash_equals((string) $config['user'], $user);
        $passOk = password_verify($pass, (string) $config['hash']);
        return $userOk && $passOk;
    }

    /** Crea o sostituisce le credenziali, conservando i campi non indicati. */
    public function save(array $changes): bool
    {
        $config = ($this->load() ?? []) + ['user' => '', 'hash' => '', 'email' => '', 'editor_url' => ''];
        if (isset($changes['password'])) {
            $changes['hash'] = password_hash((string) $changes['password'], PASSWORD_DEFAULT);
            unset($changes['password']);
        }
        $config = array_intersect_key(array_replace($config, $changes), array_flip(['user', 'hash', 'email', 'editor_url']));

        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $php = "<?php\n// Credenziali del pannello. File privato: non va versionato ne pubblicato.\nreturn "
            . var_export($config, true) . ";\n";
        $tmp = $this->file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }
        return true;
    }

    public static function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
