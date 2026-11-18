<?php
declare(strict_types=1);

namespace Kris\Auth;

/**
 * Codice del primo avvio, in config/setup_code.php.
 *
 * Finché manca config/auth.php chiunque apra l'editor vedrebbe la schermata
 * che crea l'admin: all'installazione e dopo il ripristino dell'accesso
 * (eliminare config/auth.php). Il codice lo crea Kris e lo legge solo chi ha
 * accesso ai file del sito (FTP, pannello dell'hosting): è un file PHP, quindi
 * non si scarica nemmeno dove config/ non è protetto da .htaccess.
 * Creato l'account, il file si elimina.
 */
final class SetupCode
{
    public function __construct(private string $file)
    {
    }

    /** Il codice attuale; lo crea se non c'è. null se non si riesce a scrivere il file. */
    public function ensure(): ?string
    {
        $code = $this->read();
        if ($code !== null) return $code;
        // Leggibile da una persona: 12 caratteri senza quelli che si confondono (0/O, 1/I/L)
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $code = implode('-', str_split($code, 4));
        $php = "<?php\n// Codice per creare l'accesso all'editor di Kris. Copialo nella schermata di primo avvio.\n"
            . "// Viene eliminato quando l'accesso è creato.\nreturn " . var_export($code, true) . ";\n";
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
        $tmp = $this->file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            return null;
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($this->file, true);
        return $code;
    }

    public function verify(string $input): bool
    {
        $code = $this->read();
        if ($code === null) return false;
        $normalize = fn(string $s): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
        return hash_equals($normalize($code), $normalize($input));
    }

    public function remove(): void
    {
        @unlink($this->file);
    }

    private function read(): ?string
    {
        if (!is_file($this->file)) return null;
        $code = @include $this->file;
        return is_string($code) && $code !== '' ? $code : null;
    }
}
