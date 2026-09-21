<?php
declare(strict_types=1);

/**
 * Accesso al pannello: credenziali, sessione e token CSRF.
 *
 * Questo file definisce solo funzioni: chi lo include decide quando far
 * partire la sessione. Le credenziali NON stanno nel codice ma in
 * config/auth.php, che e escluso da git; al primo avvio il pannello chiede
 * di crearle.
 */

const KRIS_AUTH_FILE = __DIR__ . '/../config/auth.php';

function kris_auth_config(): ?array
{
    if (!is_file(KRIS_AUTH_FILE)) return null;
    $config = require KRIS_AUTH_FILE;
    if (!is_array($config) || empty($config['user']) || empty($config['hash'])) return null;
    return $config;
}

function kris_is_logged_in(): bool
{
    return ($_SESSION['kris_auth'] ?? false) === true;
}

function kris_login(string $user, string $pass): bool
{
    $config = kris_auth_config();
    if ($config === null) return false;

    // hash_equals evita di rivelare lo username carattere per carattere;
    // password_verify gestisce l'hash della password.
    $userOk = hash_equals((string) $config['user'], $user);
    $passOk = password_verify($pass, (string) $config['hash']);
    if (!$userOk || !$passOk) return false;

    // Nuovo id di sessione al login: impedisce il riuso di un id noto
    // impostato prima dell'autenticazione (session fixation).
    session_regenerate_id(true);
    $_SESSION['kris_auth'] = true;
    $_SESSION['kris_user'] = $config['user'];
    return true;
}

function kris_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function kris_save_credentials(string $user, string $pass): bool
{
    $dir = dirname(KRIS_AUTH_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;

    $php = "<?php\n// Credenziali del pannello. File privato: non va versionato ne pubblicato.\nreturn "
        . var_export(['user' => $user, 'hash' => password_hash($pass, PASSWORD_DEFAULT)], true)
        . ";\n";

    return @file_put_contents(KRIS_AUTH_FILE, $php, LOCK_EX) !== false;
}

// --- CSRF ---------------------------------------------------------------

function kris_csrf_token(): string
{
    if (empty($_SESSION['kris_csrf'])) {
        $_SESSION['kris_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['kris_csrf'];
}

function kris_csrf_valid(?string $token): bool
{
    $expected = $_SESSION['kris_csrf'] ?? '';
    return is_string($token) && $expected !== '' && hash_equals($expected, $token);
}

function kris_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(kris_csrf_token()) . '">';
}

/**
 * Inserisce il token in ogni form POST della pagina.
 * Farlo qui, sull'output finale, evita di dimenticarne uno.
 */
function kris_inject_csrf(string $html): string
{
    $field = kris_csrf_field();
    return preg_replace_callback('/<form\b[^>]*>/i', function (array $m) use ($field): string {
        return preg_match('/method\s*=\s*["\']?post/i', $m[0]) ? $m[0] . $field : $m[0];
    }, $html) ?? $html;
}

// --- Schermate ----------------------------------------------------------

function kris_auth_page(string $title, string $body): never
{
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="it"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - Kris CMS</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background:#f3f4f6; display:flex; align-items:center; justify-content:center;
               height:100vh; margin:0; }
        .login-card { background:#fff; padding:40px; border-radius:12px; width:100%; max-width:380px;
                      box-shadow:0 4px 6px rgba(0,0,0,.1); text-align:center; }
        h2 { margin:0 0 20px; color:#111827; font-size:1.5rem; }
        label { display:block; text-align:left; font-size:.85rem; color:#4b5563; margin-bottom:4px; }
        input { width:100%; padding:12px; margin-bottom:15px; border:1px solid #e5e7eb;
                border-radius:6px; box-sizing:border-box; font-size:1rem; }
        button { width:100%; padding:12px; background:#3b82f6; color:#fff; border:none;
                 border-radius:6px; font-size:1rem; font-weight:600; cursor:pointer; }
        button:hover { background:#2563eb; }
        .error { background:#fee2e2; color:#b91c1c; padding:10px; border-radius:6px;
                 margin-bottom:15px; font-size:.9rem; text-align:left; }
        .hint { color:#6b7280; font-size:.85rem; line-height:1.5; text-align:left; margin:0 0 18px; }
        .brand { font-weight:800; color:#3b82f6; margin-bottom:10px; display:inline-block;
                 letter-spacing:-1px; font-size:1.2rem; }
    </style></head><body><div class="login-card">
        <div class="brand">KRIS CMS</div>
        {$body}
    </div></body></html>
    HTML;
    exit;
}

function kris_render_login(string $error = ''): never
{
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $csrf = kris_csrf_field();
    kris_auth_page('Accesso', <<<HTML
        <h2>Accesso Riservato</h2>
        {$err}
        <form method="POST">
            {$csrf}
            <input type="hidden" name="do_login" value="1">
            <label for="user">Username</label>
            <input id="user" type="text" name="user" autocomplete="username" required autofocus>
            <label for="pass">Password</label>
            <input id="pass" type="password" name="pass" autocomplete="current-password" required>
            <button type="submit">Accedi</button>
        </form>
    HTML);
}

function kris_render_setup(string $error = ''): never
{
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $csrf = kris_csrf_field();
    kris_auth_page('Primo accesso', <<<HTML
        <h2>Crea l'accesso</h2>
        <p class="hint">Non ci sono ancora credenziali configurate. Scegline adesso:
        verranno salvate cifrate in <strong>config/auth.php</strong>, fuori dal codice
        versionato.</p>
        {$err}
        <form method="POST">
            {$csrf}
            <input type="hidden" name="do_setup" value="1">
            <label for="user">Username</label>
            <input id="user" type="text" name="user" autocomplete="username" required autofocus>
            <label for="pass">Password (almeno 10 caratteri)</label>
            <input id="pass" type="password" name="pass" autocomplete="new-password" required minlength="10">
            <label for="pass2">Ripeti la password</label>
            <input id="pass2" type="password" name="pass2" autocomplete="new-password" required minlength="10">
            <button type="submit">Crea accesso</button>
        </form>
    HTML);
}
