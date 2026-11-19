<?php
declare(strict_types=1);

namespace Kris\Auth;

/**
 * Gli utenti dell'editor, in config/users.php (privato, escluso da git):
 *
 *   editor_url  indirizzo dell'editor salvato da un utente autenticato: i link
 *               di reset partono da qui, mai dall'host della richiesta
 *   users       nome => role (admin|base), hash, email, blocked, session, created_at
 *
 * Due ruoli: l'admin fa tutto quello che fa un utente base e in più gestisce
 * gli utenti (li crea con la loro password, li blocca, li elimina). Ci deve
 * sempre essere almeno un admin attivo.
 *
 * "session" è il numero della sessione valida: bloccare, eliminare o cambiare
 * la password lo fa scattare, e le sessioni aperte cadono alla richiesta
 * successiva.
 *
 * Un sito della 1.x ha config/auth.php con un solo account: alla prima lettura
 * diventa il primo admin e il file vecchio si toglie, così eliminare
 * users.php per reimpostare l'accesso non lo fa rivivere.
 */
final class Users
{
    public const MIN_PASSWORD = 10;
    public const ROLES = ['admin', 'base'];
    private const NAME = '/^[a-z0-9][a-z0-9._-]{1,39}$/';

    private ?array $data = null;

    public function __construct(private string $file, private ?string $legacyFile = null)
    {
    }

    /** Vero se c'è almeno un utente: altrimenti il primo avvio crea l'admin. */
    public function exists(): bool
    {
        return $this->read()['users'] !== [];
    }

    /** @return array<string, array> gli utenti senza l'hash della password, in ordine di nome */
    public function all(): array
    {
        $out = [];
        foreach ($this->read()['users'] as $name => $user) $out[$name] = self::public($name, $user);
        ksort($out);
        return $out;
    }

    public function get(string $name): ?array
    {
        $user = $this->read()['users'][$name] ?? null;
        return is_array($user) ? self::public($name, $user) : null;
    }

    /** Il nome dell'utente con questa email, o null. */
    public function byEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        foreach ($this->read()['users'] as $name => $user) {
            if ($email !== '' && strtolower((string) ($user['email'] ?? '')) === $email) return (string) $name;
        }
        return null;
    }

    /**
     * Controlla nome e password: 'ok', 'invalid' oppure 'blocked'. Un utente
     * bloccato lo si dice solo a chi ha la password giusta.
     */
    public function check(string $name, string $password): string
    {
        $user = $this->read()['users'][$name] ?? null;
        // Si calcola comunque un hash: la risposta non rivela se il nome esiste
        static $dummy = null;
        $hash = is_array($user) ? (string) ($user['hash'] ?? '') : ($dummy ??= password_hash(random_bytes(16), PASSWORD_DEFAULT));
        $ok = password_verify($password, $hash) && is_array($user);
        if (!$ok) return 'invalid';
        return !empty($user['blocked']) ? 'blocked' : 'ok';
    }

    /** Numero della sessione valida dell'utente; null se non esiste o è bloccato. */
    public function session(string $name): ?int
    {
        $user = $this->read()['users'][$name] ?? null;
        return is_array($user) && empty($user['blocked']) ? (int) ($user['session'] ?? 1) : null;
    }

    /** @throws UserException */
    public function create(string $name, string $password, string $email, string $role = 'base'): void
    {
        $data = $this->read();
        $name = strtolower(trim($name));
        if (preg_match(self::NAME, $name) !== 1) {
            throw new UserException('Il nome utente va da 2 a 40 caratteri: lettere minuscole, numeri, punto, trattino e _.');
        }
        if (isset($data['users'][$name])) throw new UserException("Esiste già un utente «{$name}».");
        if (!in_array($role, self::ROLES, true)) throw new UserException('Ruolo non valido.');
        self::assertPassword($password);
        $email = $this->validEmail($email, null);
        $data['users'][$name] = [
            'role' => $role,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'blocked' => false,
            'session' => 1,
            'created_at' => gmdate('c'),
        ];
        $this->write($data);
    }

    /** @throws UserException */
    public function setPassword(string $name, string $password): void
    {
        self::assertPassword($password);
        $this->change($name, fn(array $u) => ['hash' => password_hash($password, PASSWORD_DEFAULT), 'session' => (int) ($u['session'] ?? 1) + 1] + $u);
    }

    /** @throws UserException */
    public function setEmail(string $name, string $email): void
    {
        $email = $this->validEmail($email, $name);
        $this->change($name, fn(array $u) => ['email' => $email] + $u);
    }

    /** Blocca o sblocca l'accesso. @throws UserException */
    public function setBlocked(string $name, bool $blocked): void
    {
        $this->change($name, function (array $u) use ($blocked): array {
            return ['blocked' => $blocked, 'session' => (int) ($u['session'] ?? 1) + ($blocked ? 1 : 0)] + $u;
        }, $blocked);
    }

    /** @throws UserException */
    public function setRole(string $name, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) throw new UserException('Ruolo non valido.');
        $this->change($name, fn(array $u) => ['role' => $role] + $u, $role !== 'admin');
    }

    /** @throws UserException */
    public function delete(string $name): void
    {
        $data = $this->read();
        if (!isset($data['users'][$name])) throw new UserException("L'utente «{$name}» non c'è più.");
        $this->assertNotLastAdmin($data, $name);
        unset($data['users'][$name]);
        $this->write($data);
    }

    public function editorUrl(): string
    {
        return (string) ($this->read()['editor_url'] ?? '');
    }

    public function setEditorUrl(string $url): void
    {
        $data = $this->read();
        if (($data['editor_url'] ?? '') === $url) return;
        $data['editor_url'] = $url;
        $this->write($data);
    }

    public static function validAddress(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // --- interni ---------------------------------------------------------------

    private static function public(string $name, array $user): array
    {
        return [
            'name' => $name,
            'role' => in_array($user['role'] ?? '', self::ROLES, true) ? $user['role'] : 'base',
            'email' => (string) ($user['email'] ?? ''),
            'blocked' => !empty($user['blocked']),
            'created_at' => (string) ($user['created_at'] ?? ''),
        ];
    }

    private static function assertPassword(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD) throw new UserException('La password deve avere almeno ' . self::MIN_PASSWORD . ' caratteri.');
    }

    /** L'email di recupero: valida e di un solo utente. Vuota è ammessa (niente recupero). */
    private function validEmail(string $email, ?string $owner): string
    {
        $email = trim($email);
        if ($email === '') return '';
        if (!self::validAddress($email)) throw new UserException('Indirizzo email non valido.');
        $taken = $this->byEmail($email);
        if ($taken !== null && $taken !== $owner) throw new UserException('Questa email è già di un altro utente: ognuno ha la sua, per il recupero della password.');
        return $email;
    }

    private function change(string $name, callable $fn, bool $losesAdmin = false): void
    {
        $data = $this->read();
        if (!isset($data['users'][$name])) throw new UserException("L'utente «{$name}» non c'è più.");
        if ($losesAdmin) $this->assertNotLastAdmin($data, $name);
        $data['users'][$name] = $fn($data['users'][$name]);
        $this->write($data);
    }

    /** Deve restare almeno un admin che può accedere. */
    private function assertNotLastAdmin(array $data, string $name): void
    {
        if (($data['users'][$name]['role'] ?? '') !== 'admin' || !empty($data['users'][$name]['blocked'])) return;
        foreach ($data['users'] as $other => $user) {
            if ($other !== $name && ($user['role'] ?? '') === 'admin' && empty($user['blocked'])) return;
        }
        throw new UserException('È l\'unico amministratore attivo: prima rendi amministratore un altro utente.');
    }

    private function read(): array
    {
        if ($this->data !== null) return $this->data;
        $data = is_file($this->file) ? require $this->file : null;
        if (!is_array($data) && $this->legacyFile !== null && is_file($this->legacyFile)) {
            $data = $this->fromLegacy();
        }
        $data = is_array($data) ? $data : [];
        $data['users'] = is_array($data['users'] ?? null) ? $data['users'] : [];
        $data['editor_url'] = (string) ($data['editor_url'] ?? '');
        return $this->data = $data;
    }

    /** L'account unico della 1.x diventa il primo admin; il file vecchio si toglie solo se il nuovo è scritto. */
    private function fromLegacy(): ?array
    {
        $old = (new Credentials($this->legacyFile))->load();
        if ($old === null) return null;
        $name = strtolower((string) $old['user']);
        $data = ['editor_url' => (string) $old['editor_url'], 'users' => [$name => [
            'role' => 'admin', 'hash' => (string) $old['hash'], 'email' => (string) $old['email'],
            'blocked' => false, 'session' => 1, 'created_at' => gmdate('c'),
        ]]];
        try {
            $this->write($data);
            @unlink($this->legacyFile);
        } catch (UserException) {
            // Senza permessi di scrittura si continua a leggere il file vecchio
        }
        return $data;
    }

    private function write(array $data): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UserException('Non riesco a scrivere in config/: controlla i permessi con l\'hosting.');
        }
        $php = "<?php\n// Utenti dell'editor. File privato: non va versionato né pubblicato.\nreturn " . var_export($data, true) . ";\n";
        $tmp = $this->file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new UserException('Non riesco a scrivere config/users.php: controlla i permessi con l\'hosting.');
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($this->file, true);
        $this->data = $data;
    }
}
