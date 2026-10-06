<?php
declare(strict_types=1);

/**
 * Accesso al pannello: credenziali, sessione, token CSRF, recupero password.
 *
 * Questo file definisce solo funzioni: chi lo include decide quando far
 * partire la sessione. Le credenziali NON stanno nel codice ma in
 * config/auth.php, che e escluso da git; al primo avvio il pannello chiede
 * di crearle. La logica sta in Kris\Auth (core/auth/).
 */

use Kris\Auth\Credentials;
use Kris\Auth\PasswordReset;

const KRIS_AUTH_FILE = KRIS_ROOT . '/config/auth.php';
const KRIS_RESET_FILE = KRIS_ROOT . '/config/auth_reset.json';

/**
 * Messaggio per chi non riesce a recuperare l'accesso con l'email. Le pagine
 * di accesso sono pubbliche: si rimanda all'hosting senza spiegare come si
 * reimposta l'accesso.
 */
const KRIS_HOSTING_HELP = "Per ripristinare l'accesso contatta l'amministratore dell'hosting.";

function kris_credentials(): Credentials
{
    return new Credentials(KRIS_AUTH_FILE);
}

function kris_password_reset(): PasswordReset
{
    return new PasswordReset(KRIS_RESET_FILE);
}

function kris_auth_config(): ?array
{
    return kris_credentials()->load();
}

function kris_is_logged_in(): bool
{
    return ($_SESSION['kris_auth'] ?? false) === true;
}

function kris_login(string $user, string $pass): bool
{
    if (!kris_credentials()->verify($user, $pass)) return false;

    // Nuovo id di sessione al login: impedisce il riuso di un id noto
    // impostato prima dell'autenticazione (session fixation).
    session_regenerate_id(true);
    $_SESSION['kris_auth'] = true;
    $_SESSION['kris_user'] = kris_auth_config()['user'];
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

function kris_save_credentials(string $user, string $pass, string $email = ''): bool
{
    $changes = ['user' => $user, 'password' => $pass];
    if ($email !== '') {
        $changes += ['email' => $email, 'editor_url' => kris_current_editor_url()];
    }
    return kris_credentials()->save($changes);
}

/** Indirizzo dell'editor nella richiesta corrente (solo per richieste fidate). */
function kris_current_editor_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) $host = 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . (string) ($_SERVER['SCRIPT_NAME'] ?? '/editor/index.php');
}

/**
 * Invia un'email di testo con mail() di PHP. Restituisce false se l'hosting
 * la rifiuta; che arrivi davvero lo dice solo la casella di chi la riceve.
 */
function kris_send_mail(string $to, string $subject, string $body): bool
{
    if (!Credentials::validEmail($to) || !function_exists('mail')) return false;
    $config = kris_auth_config() ?? [];
    $host = (string) (parse_url((string) ($config['editor_url'] ?? ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.-]/', '', strtolower($host)));
    $headers = [
        'From: Kris <noreply@' . ($host !== '' ? $host : 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $subject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8') : $subject;
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

/** Email con il link di reset; false se non c'e un'email o l'invio fallisce. */
function kris_send_reset_link(): bool
{
    $config = kris_auth_config();
    if ($config === null || !Credentials::validEmail((string) $config['email']) || $config['editor_url'] === '') return false;
    $token = kris_password_reset()->create();
    $link = $config['editor_url'] . '?reset=' . $token;
    $body = "Ciao,\n\nqualcuno ha chiesto di reimpostare la password dell'editor del sito.\n"
        . "Per sceglierne una nuova apri questo link entro 30 minuti:\n\n{$link}\n\n"
        . "Se non sei stato tu, ignora questa email: la password attuale resta valida.\n\n"
        . "Nome utente: {$config['user']}\n";
    return kris_send_mail((string) $config['email'], 'Reimposta la password dell\'editor', $body);
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

function kris_auth_page(string $title, string $body): void // termina sempre con exit
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
        .ok { background:#dcfce7; color:#166534; padding:10px; border-radius:6px;
              margin-bottom:15px; font-size:.9rem; text-align:left; }
        .help { color:#6b7280; font-size:.8rem; line-height:1.5; text-align:left; margin:18px 0 0;
                padding-top:14px; border-top:1px solid #e5e7eb; }
        .alt { display:block; margin-top:16px; font-size:.85rem; color:#2563eb; }
        .brand { font-weight:800; color:#3b82f6; margin-bottom:10px; display:inline-block;
                 letter-spacing:-1px; font-size:1.2rem; }
    </style></head><body><div class="login-card">
        <div class="brand">KRIS CMS</div>
        {$body}
    </div></body></html>
    HTML;
    exit;
}

function kris_render_login(string $error = '', string $notice = ''): void // termina sempre con exit
{
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $err .= $notice !== '' ? '<div class="ok">' . htmlspecialchars($notice) . '</div>' : '';
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
        <a class="alt" href="?forgot=1">Password dimenticata?</a>
    HTML);
}

/** Recupero: invia il link all'email dell'admin, o spiega a chi rivolgersi. */
function kris_render_forgot(string $error = '', string $notice = ''): void // termina sempre con exit
{
    $config = kris_auth_config() ?? [];
    $help = htmlspecialchars(KRIS_HOSTING_HELP);
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $err .= $notice !== '' ? '<div class="ok">' . htmlspecialchars($notice) . '</div>' : '';
    $csrf = kris_csrf_field();
    // Se l'errore lo dice gia, l'indicazione per l'hosting non si ripete.
    $footer = $error === '' ? '<p class="help">Non arriva nessuna email? Controlla anche lo spam. ' . $help . '</p>' : '';
    if (!Kris\Auth\Credentials::validEmail((string) ($config['email'] ?? '')) || ($config['editor_url'] ?? '') === '') {
        $body = <<<HTML
            <h2>Password dimenticata</h2>
            <p class="hint">Su questo sito non è configurata un'email per il recupero della password.</p>
            <p class="hint">{$help}</p>
            <a class="alt" href="?">Torna all'accesso</a>
        HTML;
    } else {
        $body = <<<HTML
            <h2>Password dimenticata</h2>
            {$err}
            <p class="hint">Invieremo un link per scegliere una nuova password all'email dell'amministratore del sito. Il link vale 30 minuti.</p>
            <form method="POST">
                {$csrf}
                <input type="hidden" name="do_forgot" value="1">
                <button type="submit">Invia il link</button>
            </form>
            {$footer}
            <a class="alt" href="?">Torna all'accesso</a>
        HTML;
    }
    kris_auth_page('Password dimenticata', $body);
}

/** Nuova password da un link di reset. */
function kris_render_reset(string $token, string $error = ''): void // termina sempre con exit
{
    if (!kris_password_reset()->isValid($token)) {
        kris_auth_page('Link non valido', <<<HTML
            <h2>Link scaduto</h2>
            <p class="hint">Il link non è più valido: è già stato usato oppure sono passati più di 30 minuti.</p>
            <a class="alt" href="?forgot=1">Chiedine uno nuovo</a>
        HTML);
    }
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $csrf = kris_csrf_field();
    $t = htmlspecialchars($token);
    $min = Kris\Auth\Credentials::MIN_PASSWORD;
    kris_auth_page('Nuova password', <<<HTML
        <h2>Scegli una nuova password</h2>
        {$err}
        <form method="POST" action="?">
            {$csrf}
            <input type="hidden" name="do_reset" value="1">
            <input type="hidden" name="token" value="{$t}">
            <label for="pass">Nuova password (almeno {$min} caratteri)</label>
            <input id="pass" type="password" name="pass" autocomplete="new-password" required minlength="{$min}" autofocus>
            <label for="pass2">Ripeti la password</label>
            <input id="pass2" type="password" name="pass2" autocomplete="new-password" required minlength="{$min}">
            <button type="submit">Salva la password</button>
        </form>
    HTML);
}

function kris_render_setup(string $error = ''): void // termina sempre con exit
{
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $csrf = kris_csrf_field();
    kris_auth_page('Primo accesso', <<<HTML
        <h2>Crea l'accesso</h2>
        <p class="hint">Non ci sono ancora credenziali configurate: scegli adesso quelle
        dell'amministratore del sito.</p>
        {$err}
        <form method="POST">
            {$csrf}
            <input type="hidden" name="do_setup" value="1">
            <label for="user">Username</label>
            <input id="user" type="text" name="user" autocomplete="username" required autofocus>
            <label for="email">Email (per recuperare la password)</label>
            <input id="email" type="email" name="email" autocomplete="email" required>
            <label for="pass">Password (almeno 10 caratteri)</label>
            <input id="pass" type="password" name="pass" autocomplete="new-password" required minlength="10">
            <label for="pass2">Ripeti la password</label>
            <input id="pass2" type="password" name="pass2" autocomplete="new-password" required minlength="10">
            <button type="submit">Crea accesso</button>
        </form>
    HTML);
}
