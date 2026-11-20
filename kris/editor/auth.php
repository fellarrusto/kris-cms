<?php
declare(strict_types=1);

/**
 * Accesso al pannello: utenti, sessione, token CSRF, recupero password.
 *
 * Questo file definisce solo funzioni: chi lo include decide quando far
 * partire la sessione. Gli utenti stanno in config/users.php (escluso da git,
 * vedi Kris\Auth\Users); al primo avvio il pannello crea l'amministratore.
 */

use Kris\Auth\PasswordReset;
use Kris\Auth\Users;

const KRIS_USERS_FILE = KRIS_ROOT . '/config/users.php';
const KRIS_LEGACY_AUTH_FILE = KRIS_ROOT . '/config/auth.php';
const KRIS_RESET_FILE = KRIS_ROOT . '/config/auth_reset.json';

/**
 * Messaggio per chi non riesce a recuperare l'accesso con l'email. Le pagine
 * di accesso sono pubbliche: si rimanda a chi gestisce il sito senza
 * spiegare come si reimposta l'accesso.
 */
const KRIS_HOSTING_HELP = "Per ripristinare l'accesso chiedi a un amministratore del sito o all'amministratore dell'hosting.";

function kris_users(): Users
{
    static $users = null;
    return $users ??= new Users(KRIS_USERS_FILE, KRIS_LEGACY_AUTH_FILE);
}

const KRIS_SETUP_CODE_FILE = KRIS_ROOT . '/config/setup_code.php';

function kris_setup_code(): \Kris\Auth\SetupCode
{
    return new \Kris\Auth\SetupCode(KRIS_SETUP_CODE_FILE);
}

function kris_password_reset(): PasswordReset
{
    return new PasswordReset(KRIS_RESET_FILE);
}

/**
 * Vero se la sessione è di un utente che esiste, non è bloccato e non ha
 * cambiato password nel frattempo: altrimenti la sessione si chiude.
 */
function kris_is_logged_in(): bool
{
    if (($_SESSION['kris_auth'] ?? false) !== true) return false;
    $name = (string) ($_SESSION['kris_user'] ?? '');
    $valid = kris_users()->session($name);
    if ($valid === null || $valid !== (int) ($_SESSION['kris_session'] ?? 0)) {
        unset($_SESSION['kris_auth'], $_SESSION['kris_user'], $_SESSION['kris_session'], $_SESSION['kris_role']);
        return false;
    }
    return true;
}

/** L'utente della sessione, o null. */
function kris_current_user(): ?array
{
    return kris_is_logged_in() ? kris_users()->get((string) $_SESSION['kris_user']) : null;
}

/** L'unico potere in più dell'admin: gestire gli utenti. */
function kris_is_admin(): bool
{
    return (kris_current_user()['role'] ?? '') === 'admin';
}

const KRIS_ATTEMPTS_FILE = KRIS_ROOT . '/config/auth_attempts.json';

function kris_login_throttle(): \Kris\Auth\LoginThrottle
{
    return new \Kris\Auth\LoginThrottle(KRIS_ATTEMPTS_FILE);
}

/** Secondi da aspettare prima di poter riprovare ad accedere da questo indirizzo. */
function kris_login_wait(): int
{
    return kris_login_throttle()->waitSeconds((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Accede: 'ok', 'invalid' o 'blocked' (solo con la password giusta). */
function kris_login(string $user, string $pass): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $user = strtolower(trim($user));
    $result = kris_users()->check($user, $pass);
    if ($result !== 'ok') {
        if ($result === 'invalid') kris_login_throttle()->fail($ip);
        return $result;
    }
    kris_login_throttle()->succeed($ip);
    kris_start_session($user);
    return 'ok';
}

/** Sessione nuova per l'utente (nuovo id: niente session fixation). */
function kris_start_session(string $user): void
{
    session_regenerate_id(true);
    $_SESSION['kris_auth'] = true;
    $_SESSION['kris_user'] = $user;
    $_SESSION['kris_session'] = (int) kris_users()->session($user);
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

/** Primo avvio: crea l'amministratore. @throws \Kris\Auth\UserException */
function kris_create_first_admin(string $user, string $pass, string $email): void
{
    kris_users()->create($user, $pass, $email, 'admin');
    kris_users()->setEditorUrl(kris_current_editor_url());
}

/** La richiesta arriva in https, anche attraverso il proxy dell'hosting. */
function kris_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Indirizzo dell'editor nella richiesta corrente (solo per richieste fidate). */
function kris_current_editor_url(): string
{
    $https = kris_is_https();
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) $host = 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . (string) ($_SERVER['SCRIPT_NAME'] ?? '/kris-admin/index.php');
}

/**
 * Invia un'email di testo con mail() di PHP. Restituisce false se l'hosting
 * la rifiuta; che arrivi davvero lo dice solo la casella di chi la riceve.
 */
function kris_send_mail(string $to, string $subject, string $body): bool
{
    if (!Users::validAddress($to) || !function_exists('mail')) return false;
    $host = (string) (parse_url(kris_users()->editorUrl(), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));
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

/**
 * Link di reset all'utente con questa email. 'sent', 'unknown' (nessun utente
 * con quell'email, o bloccato), 'limit' (troppe richieste) o 'failed'. Chi
 * chiede vede la stessa risposta per sent e unknown: non si scopre quali
 * email esistono.
 */
function kris_send_reset_link(string $email): string
{
    $users = kris_users();
    $name = $users->byEmail($email);
    if ($name === null || $users->session($name) === null || $users->editorUrl() === '') return 'unknown';
    if (!kris_password_reset()->canRequest($name)) return 'limit';
    $token = kris_password_reset()->create($name);
    // Indirizzo salvato prima della 2.0: l'editor ora è in /kris-admin/
    $link = preg_replace('#/editor/index\.php$#', '/kris-admin/index.php', $users->editorUrl()) . '?reset=' . $token;
    $body = "Ciao,\n\nqualcuno ha chiesto di reimpostare la password dell'editor del sito.\n"
        . "Per sceglierne una nuova apri questo link entro 30 minuti:\n\n{$link}\n\n"
        . "Se non sei stato tu, ignora questa email: la password attuale resta valida.\n\n"
        . "Nome utente: {$name}\n";
    return kris_send_mail((string) $users->get($name)['email'], 'Reimposta la password dell\'editor', $body) ? 'sent' : 'failed';
}

/**
 * Intestazioni di ogni risposta dell'editor: niente pagina dentro un iframe
 * di un altro sito (clickjacking su «Pubblica», «Approva gli script»…), niente
 * interpretazione dei tipi, niente indirizzo dell'editor ai siti esterni.
 */
function kris_security_headers(): void
{
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
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
    <title>{$title} · Kris</title>
    <script>try{var t=localStorage.getItem('kris-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t;}catch(e){}</script>
    <style>
        :root { color-scheme:light; --ground:#EDEDEA; --surface:#fff; --text:#17171A; --muted:#6B6A66; --border:#DCDBD6; --line:#EEEDE9;
                --primary:#17171A; --on-primary:#fff; --danger-soft:#FDECEC; --danger-ink:#7F1D1D; --ok-soft:#E7F5EC; --ok-ink:#14532D; --focus:#2563EB; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { color-scheme:dark; --ground:#0F0F0E; --surface:#1A1A19; --text:#EDEDEA; --muted:#A3A29C;
                --border:#3A3936; --line:#2A2A28; --primary:#EDEDEA; --on-primary:#17171A; --danger-soft:#3A1616; --danger-ink:#FFB4B4; --ok-soft:#16301F; --ok-ink:#A7E8C1; --focus:#7AA7FF; } }
        :root[data-theme="dark"] { color-scheme:dark; --ground:#0F0F0E; --surface:#1A1A19; --text:#EDEDEA; --muted:#A3A29C;
                --border:#3A3936; --line:#2A2A28; --primary:#EDEDEA; --on-primary:#17171A; --danger-soft:#3A1616; --danger-ink:#FFB4B4; --ok-soft:#16301F; --ok-ink:#A7E8C1; --focus:#7AA7FF; }
        * { box-sizing:border-box; }
        body { font:15px/1.5 Geist, "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--ground); color:var(--text);
               display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:16px; -webkit-font-smoothing:antialiased; }
        .login-card { background:var(--surface); padding:32px; border-radius:22px; width:100%; max-width:400px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:600; font-size:17px; margin-bottom:24px; }
        .brand::before { content:"K"; width:28px; height:28px; border-radius:8px; background:var(--primary); color:var(--on-primary); display:grid; place-items:center; font-size:14px; }
        h2 { margin:0 0 16px; font-size:24px; font-weight:500; letter-spacing:-.02em; }
        label { display:block; font-size:14px; font-weight:500; margin-bottom:6px; }
        input { width:100%; min-height:44px; padding:0 14px; margin-bottom:14px; border:1px solid var(--border); border-radius:12px;
                font:inherit; background:var(--surface); color:var(--text); }
        input:focus, button:focus-visible, a:focus-visible { outline:2px solid var(--focus); outline-offset:1px; border-color:transparent; }
        button { width:100%; min-height:44px; padding:0 18px; background:var(--primary); color:var(--on-primary); border:none;
                 border-radius:999px; font:inherit; font-weight:500; cursor:pointer; margin-top:4px; }
        button:hover { opacity:.9; }
        .error, .ok { padding:10px 14px; border-radius:12px; margin-bottom:14px; font-size:14px; }
        .error { background:var(--danger-soft); color:var(--danger-ink); }
        .ok { background:var(--ok-soft); color:var(--ok-ink); }
        .hint { color:var(--muted); font-size:14px; line-height:1.5; margin:0 0 16px; }
        .help { color:var(--muted); font-size:13px; line-height:1.5; margin:18px 0 0; padding-top:14px; border-top:1px solid var(--line); }
        .alt { display:block; margin-top:16px; font-size:14px; color:var(--text); text-underline-offset:3px; }
        code { font-family:ui-monospace, Menlo, Consolas, monospace; font-size:13px; }
    </style></head><body><div class="login-card">
        <div class="brand">Kris</div>
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
        <h2>Accedi all'editor</h2>
        {$err}
        <form method="POST">
            {$csrf}
            <input type="hidden" name="do_login" value="1">
            <label for="user">Nome utente</label>
            <input id="user" type="text" name="user" autocomplete="username" required autofocus>
            <label for="pass">Password</label>
            <input id="pass" type="password" name="pass" autocomplete="current-password" required>
            <button type="submit">Accedi</button>
        </form>
        <a class="alt" href="?forgot=1">Password dimenticata?</a>
    HTML);
}

/** Recupero: chi ha un'email associata riceve il link per scegliere una password nuova. */
function kris_render_forgot(string $error = '', string $notice = ''): void // termina sempre con exit
{
    $help = htmlspecialchars(KRIS_HOSTING_HELP);
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $err .= $notice !== '' ? '<div class="ok">' . htmlspecialchars($notice) . '</div>' : '';
    $csrf = kris_csrf_field();
    kris_auth_page('Password dimenticata', <<<HTML
        <h2>Password dimenticata</h2>
        {$err}
        <p class="hint">Scrivi l'email associata al tuo utente: ti mandiamo un link per scegliere una nuova password, valido 30 minuti.</p>
        <form method="POST">
            {$csrf}
            <input type="hidden" name="do_forgot" value="1">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" autocomplete="email" required autofocus>
            <button type="submit">Invia il link</button>
        </form>
        <p class="help">Non arriva nessuna email? Controlla anche lo spam. {$help}</p>
        <a class="alt" href="?">Torna all'accesso</a>
    HTML);
}

/** Nuova password da un link di reset. */
function kris_render_reset(string $token, string $error = ''): void // termina sempre con exit
{
    if (kris_password_reset()->userOf($token) === null) {
        kris_auth_page('Link non valido', <<<HTML
            <h2>Link scaduto</h2>
            <p class="hint">Il link non è più valido: è già stato usato oppure sono passati più di 30 minuti.</p>
            <a class="alt" href="?forgot=1">Chiedine uno nuovo</a>
        HTML);
    }
    $err = $error !== '' ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
    $csrf = kris_csrf_field();
    $t = htmlspecialchars($token);
    $min = Kris\Auth\Users::MIN_PASSWORD;
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
        <p class="hint">Non ci sono ancora utenti: crea adesso l'amministratore del sito.
        Gli altri utenti li crea lui dall'editor.</p>
        <p class="hint">Per dimostrare che il sito è tuo, apri con l'FTP o il pannello dell'hosting il file
        <strong>config/setup_code.php</strong> e copia qui il codice che contiene.</p>
        {$err}
        <form method="POST">
            {$csrf}
            <input type="hidden" name="do_setup" value="1">
            <label for="setup_code">Codice di primo avvio</label>
            <input id="setup_code" type="text" name="setup_code" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX" required autofocus>
            <label for="user">Nome utente</label>
            <input id="user" type="text" name="user" autocomplete="username" required>
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
