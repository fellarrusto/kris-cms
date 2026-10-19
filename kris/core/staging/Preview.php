<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Anteprima dello staging sul sito pubblico, solo per chi ha una sessione
 * dell'editor. Si chiede con ?kris_preview=1; senza sessione la pagina
 * risponde 404, come se non esistesse.
 *
 * I file del tema modificati stanno in data/staging/, che non e pubblica:
 * li serve index.php stesso (?kris_preview_asset=css/<nome>.css, js/..., images/...),
 * sempre solo a un admin, e la pagina viene riscritta per puntare li.
 *
 * Codice nuovo (JavaScript, SVG, script e gestori di eventi nei template)
 * gira nel browser dell'admin, con la sessione dell'editor aperta. Finche
 * l'admin non lo approva (Staging::approveCode) l'anteprima lo blocca con una
 * Content-Security-Policy che lascia girare solo gli script di Kris, e un
 * popup chiede di assumersi la responsabilita prima di eseguirlo.
 */
final class Preview
{
    /** Cookie con l'impronta del codice per cui l'admin ha scelto di guardare senza script. */
    private const NOSCRIPT_COOKIE = 'kris_preview_noscript';

    private static ?string $nonce = null;

    /** Vero se la richiesta chiede l'anteprima o un suo file. Solo allora si apre la sessione. */
    public static function requested(array $query): bool
    {
        return isset($query['kris_preview']) || isset($query['kris_preview_asset']);
    }

    /** Vero se chi chiede ha una sessione dell'editor. La sessione si legge e si chiude subito. */
    public static function isAdmin(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Stesso cookie dell'editor, senza tenerlo bloccato mentre la pagina si rende
            @session_start(['read_and_close' => true]);
        }
        return ($_SESSION['kris_auth'] ?? false) === true;
    }

    /**
     * Indirizzo di un file dell'anteprima. Finisce con &kris_preview=1 e non
     * con l'estensione del file: alcuni proxy degli hosting (per esempio
     * quello di Aruba) trattano come statici gli indirizzi che finiscono in
     * .png o .css, e la richiesta potrebbe arrivare senza la sessione.
     * $html: con &amp; per un attributo HTML.
     */
    public static function assetUrl(string $asset, bool $html = false): string
    {
        return 'index.php?kris_preview_asset=' . $asset . ($html ? '&amp;' : '&') . 'kris_preview=1';
    }

    /** Nonce degli script di Kris nella pagina in anteprima, uno per richiesta. */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(18));
    }

    private const UPLOAD_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'pdf' => 'application/pdf',
    ];

    /** Invia un file del tema o un'immagine dello staging, oppure risponde 404. */
    public static function serveAsset(Staging $staging, string $asset): void
    {
        $headers = [];
        if (preg_match('#^css/[a-z0-9][a-z0-9_-]*\.css$#', $asset) === 1) {
            $file = $staging->dir() . '/assets/' . $asset;
            $type = 'text/css; charset=utf-8';
        } elseif (preg_match('#^js/[a-z0-9][a-z0-9_-]*\.js$#', $asset) === 1 && $staging->codeApproved()) {
            // Il JavaScript in attesa si serve solo dopo l'approvazione
            $file = $staging->dir() . '/assets/' . $asset;
            $type = 'text/javascript; charset=utf-8';
        } elseif (preg_match('#^images/[a-z0-9][a-z0-9_-]*\.svg$#', $asset) === 1) {
            $file = $staging->dir() . '/assets/' . $asset;
            $type = 'image/svg+xml';
            // Aperto da solo, un SVG non esegue script: dentro un <img> non li esegue comunque
            $headers[] = "Content-Security-Policy: script-src 'none'; sandbox";
        } elseif (preg_match('#^uploads/([A-Za-z0-9][A-Za-z0-9._-]*)\.([a-z0-9]+)$#', $asset, $m) === 1 && isset(self::UPLOAD_TYPES[$m[2]])) {
            $file = $staging->dir() . '/' . $asset;
            $type = self::UPLOAD_TYPES[$m[2]];
        } else {
            $file = '';
            $type = '';
        }
        if ($file === '' || !is_file($file)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        foreach ($headers as $header) header($header);
        if (str_starts_with($asset, 'css/')) {
            echo self::rebaseCss((string) file_get_contents($file), $staging);
            return;
        }
        readfile($file);
    }

    /**
     * Un foglio di stile dello staging si serve da index.php, non da
     * assets/css/: i suoi url() e @import relativi ("../images/x.svg")
     * finirebbero fuori posto. Si riscrivono come percorsi dalla root del
     * sito, e quelli dei file del tema o delle immagini in attesa puntano
     * all'anteprima.
     */
    public static function rebaseCss(string $css, Staging $staging): string
    {
        $resolve = function (string $url) use ($staging): string {
            if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $url) === 1) {
                return $url; // assoluto, dalla radice del dominio, data:, frammento
            }
            [$path, $suffix] = array_pad(preg_split('/(?=[?#])/', $url, 2), 2, '');
            $parts = [];
            foreach (explode('/', 'assets/css/' . $path) as $segment) {
                if ($segment === '' || $segment === '.') continue;
                if ($segment === '..') { array_pop($parts); continue; }
                $parts[] = $segment;
            }
            $relative = implode('/', $parts);
            if (preg_match('#^assets/((?:css/[a-z0-9][a-z0-9_-]*\.css)|(?:images/[a-z0-9][a-z0-9_-]*\.svg))$#', $relative, $m) === 1
                && is_file($staging->dir() . '/' . $relative)) {
                return self::assetUrl($m[1]);
            }
            if (preg_match('#^assets/uploads/([A-Za-z0-9][A-Za-z0-9._-]*)$#', $relative, $m) === 1 && is_file($staging->dir() . '/uploads/' . $m[1])) {
                return self::assetUrl('uploads/' . $m[1]);
            }
            return $relative . $suffix;
        };
        $css = (string) preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i',
            fn(array $m): string => 'url(' . $m[1] . $resolve(trim($m[2])) . $m[1] . ')',
            $css
        );
        return (string) preg_replace_callback(
            '/@import\s+([\'"])([^\'"]+)\1/i',
            fn(array $m): string => '@import ' . $m[1] . $resolve($m[2]) . $m[1],
            $css
        );
    }

    /**
     * Intestazioni di una pagina in anteprima: mai in cache, mai indicizzata.
     * Con codice in attesa non ancora approvato girano solo gli script di Kris.
     */
    public static function headers(?Staging $staging = null): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        if ($staging !== null && !$staging->codeApproved()) {
            header("Content-Security-Policy: script-src 'nonce-" . self::nonce() . "'; object-src 'none'; base-uri 'self'");
            // "Guarda senza script": il popup non torna a ogni pagina, finche il codice non cambia
            if (isset($_GET['kris_preview_noscript']) && ($fingerprint = $staging->codeFingerprint()) !== null) {
                setcookie(self::NOSCRIPT_COOKIE, $fingerprint, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
                $_COOKIE[self::NOSCRIPT_COOKIE] = $fingerprint;
            }
        }
    }

    /**
     * Pagina in anteprima: file del tema dello staging, link interni che
     * restano in anteprima, barra sottile fissa in cima alla pagina (come
     * quella di WordPress) e, con codice da approvare, il popup.
     */
    public static function decorate(string $html, Staging $staging, string $editorUrl, string $exitUrl): string
    {
        // assets/css|js|images/<nome> -> file dello staging, se e stato modificato o creato
        $html = (string) preg_replace_callback(
            '#(["\'])(?:\./)?assets/((?:css/[a-z0-9][a-z0-9_-]*\.css)|(?:js/[a-z0-9][a-z0-9_-]*\.js)|(?:images/[a-z0-9][a-z0-9_-]*\.svg))(?:\?[^"\']*)?\1#i',
            function (array $m) use ($staging): string {
                return is_file($staging->dir() . '/assets/' . $m[2])
                    ? $m[1] . self::assetUrl($m[2]) . $m[1]
                    : $m[0];
            },
            $html
        );

        // assets/uploads/<nome> -> immagine caricata nello staging, non ancora pubblica
        $html = (string) preg_replace_callback(
            '#(["\'\s,(])(?:\./)?assets/uploads/([A-Za-z0-9][A-Za-z0-9._-]*)#',
            function (array $m) use ($staging): string {
                return is_file($staging->dir() . '/uploads/' . $m[2])
                    ? $m[1] . self::assetUrl('uploads/' . $m[2])
                    : $m[0];
            },
            $html
        );

        // Link alle pagine del sito: restano in anteprima
        $html = (string) preg_replace_callback(
            '#\bhref=(["\'])((?:\./)?index\.php)(\?[^"\'\#]*)?(\#[^"\']*)?\1#i',
            function (array $m): string {
                $query = $m[3] ?? '';
                if (str_contains($query, 'kris_preview')) return $m[0];
                $query = $query === '' || $query === '?' ? '?kris_preview=1' : $query . '&amp;kris_preview=1';
                return 'href=' . $m[1] . $m[2] . $query . ($m[4] ?? '') . $m[1];
            },
            $html
        );

        $approved = $staging->codeApproved();
        $bar = self::bar($editorUrl, $exitUrl, $staging->hasChanges(), $approved ? 0 : count($staging->codeFiles()));
        if (!$approved && (isset($_GET['kris_preview_approve']) || ($_COOKIE[self::NOSCRIPT_COOKIE] ?? null) !== $staging->codeFingerprint())) {
            $bar .= self::approvalDialog($staging, $editorUrl);
        }
        $html = (string) preg_replace('#<head(\s[^>]*)?>#i', "\$0\n<meta name=\"robots\" content=\"noindex\">\n" . self::BAR_STYLE, $html, 1);
        // In cima al body, prima di tutto il resto, come la barra di WordPress
        return preg_match('#<body(\s[^>]*)?>#i', $html) === 1
            ? (string) preg_replace('#<body(\s[^>]*)?>#i', "\$0\n" . str_replace('$', '\\$', $bar), $html, 1)
            : $bar . $html;
    }

    /** La stessa pagina senza anteprima. */
    public static function exitUrl(array $query): string
    {
        unset($query['kris_preview'], $query['kris_preview_asset'], $query['kris_preview_noscript'], $query['kris_preview_approve']);
        return 'index.php' . ($query ? '?' . http_build_query($query) : '');
    }

    /**
     * Stili della barra e del popup: propri e con !important, per non
     * dipendere dal tema del sito. La pagina scende dell'altezza della barra,
     * che resta sopra a tutto.
     */
    private const BAR_STYLE = <<<'HTML'
<style id="kris-preview-style">
html{margin-top:36px!important}
#kris-preview-bar{position:fixed!important;top:0!important;left:0!important;right:0!important;z-index:2147483647!important;height:36px!important;margin:0!important;padding:0 12px!important;display:flex!important;align-items:center!important;gap:12px!important;background:#202b27!important;color:#e8efe9!important;font:13px/1 'Segoe UI',system-ui,-apple-system,sans-serif!important;box-shadow:0 1px 4px rgba(0,0,0,.3)!important;box-sizing:border-box!important;text-align:left!important}
#kris-preview-bar *{box-sizing:border-box!important;font:inherit!important;letter-spacing:normal!important;text-transform:none!important}
#kris-preview-bar .kpb-tag{flex:none!important;padding:4px 8px!important;border-radius:4px!important;background:#e2b54a!important;color:#202b27!important;font-weight:700!important}
#kris-preview-bar .kpb-text{flex:1!important;min-width:0!important;overflow:hidden!important;white-space:nowrap!important;text-overflow:ellipsis!important}
#kris-preview-bar a{flex:none!important;color:#fff!important;text-decoration:none!important;padding:5px 10px!important;border-radius:4px!important;border:1px solid #63716a!important;background:transparent!important}
#kris-preview-bar a.kpb-main{background:#b7d6a6!important;border-color:#b7d6a6!important;color:#202b27!important;font-weight:600!important}
#kris-preview-bar a.kpb-code{border-color:#e2b54a!important;color:#e2b54a!important}
#kris-preview-bar a:focus-visible{outline:2px solid #e2b54a!important;outline-offset:1px!important}
@media(max-width:640px){#kris-preview-bar .kpb-text{display:none!important}#kris-preview-bar{justify-content:space-between!important}}
#kris-code-approval{position:fixed!important;inset:0!important;z-index:2147483647!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:16px!important;background:rgba(10,20,16,.62)!important;font:14px/1.55 'Segoe UI',system-ui,-apple-system,sans-serif!important;color:#202b27!important;text-align:left!important}
#kris-code-approval *{box-sizing:border-box!important;letter-spacing:normal!important;text-transform:none!important}
#kris-code-approval .kca-box{width:100%!important;max-width:560px!important;max-height:calc(100vh - 32px)!important;overflow:auto!important;background:#fff!important;border-radius:12px!important;padding:24px!important;box-shadow:0 18px 50px rgba(0,0,0,.35)!important}
#kris-code-approval h2{margin:0 0 10px!important;font:700 19px/1.3 'Segoe UI',system-ui,sans-serif!important;color:#202b27!important}
#kris-code-approval p{margin:0 0 12px!important;font:inherit!important;color:#3b4842!important}
#kris-code-approval ul{margin:0 0 14px!important;padding:10px 12px 10px 30px!important;background:#fff7e6!important;border:1px solid #ecd9ae!important;border-radius:8px!important}
#kris-code-approval li{font:13px/1.6 Consolas,'Courier New',monospace!important;color:#202b27!important}
#kris-code-approval label{display:flex!important;gap:10px!important;align-items:flex-start!important;margin:0 0 16px!important;font:inherit!important;color:#202b27!important;cursor:pointer!important}
#kris-code-approval input[type=checkbox]{flex:none!important;width:17px!important;height:17px!important;margin:2px 0 0!important;accent-color:#166c51!important}
#kris-code-approval .kca-actions{display:flex!important;flex-wrap:wrap!important;gap:10px!important;justify-content:flex-end!important}
#kris-code-approval button,#kris-code-approval a{display:inline-flex!important;align-items:center!important;padding:9px 15px!important;border-radius:7px!important;font:600 13px/1.2 'Segoe UI',system-ui,sans-serif!important;text-decoration:none!important;cursor:pointer!important}
#kris-code-approval button{background:#166c51!important;color:#fff!important;border:1px solid #166c51!important}
#kris-code-approval a{background:#fff!important;color:#202b27!important;border:1px solid #c9d7c8!important}
#kris-code-approval button:focus-visible,#kris-code-approval a:focus-visible,#kris-code-approval input:focus-visible{outline:2px solid #e2b54a!important;outline-offset:2px!important}
</style>
HTML;

    /** Barra sottile fissa in cima alla pagina. $pendingCode: file con codice in attesa di approvazione. */
    private static function bar(string $editorUrl, string $exitUrl, bool $hasChanges, int $pendingCode): string
    {
        $url = htmlspecialchars($editorUrl, ENT_QUOTES);
        $exit = htmlspecialchars($exitUrl, ENT_QUOTES);
        $nonce = htmlspecialchars(self::nonce(), ENT_QUOTES);
        $text = $hasChanges
            ? 'Stai vedendo le modifiche non ancora pubblicate. I visitatori vedono ancora il sito attuale.'
            : 'Non ci sono modifiche in attesa: stai vedendo il sito pubblicato.';
        $code = '';
        if ($pendingCode > 0) {
            $text = 'Anteprima senza script: ' . $pendingCode . ($pendingCode === 1 ? ' file con codice nuovo aspetta' : ' file con codice nuovo aspettano') . ' la tua approvazione.';
            $code = '<a class="kpb-code" href="' . htmlspecialchars(self::currentUrl(['kris_preview_noscript' => null, 'kris_preview_approve' => '1']), ENT_QUOTES) . '">Approva gli script</a>';
        }
        return <<<HTML
<div id="kris-preview-bar" role="region" aria-label="Anteprima">
<span class="kpb-tag">Anteprima</span><span class="kpb-text">{$text}</span>{$code}<a class="kpb-main" href="{$url}">Rivedi e pubblica</a><a href="{$exit}">Esci</a>
</div>
<script nonce="{$nonce}">
/* Gli header fissati in cima al sito scendono sotto la barra invece di finirci dietro */
(function () {
  var bar = document.getElementById('kris-preview-bar');
  function shift() {
    var h = bar.offsetHeight;
    document.querySelectorAll('body *').forEach(function (el) {
      if (el === bar || bar.contains(el) || el.hasAttribute('data-kpb-shifted')) return;
      var cs = getComputedStyle(el);
      if ((cs.position === 'fixed' || cs.position === 'sticky') && parseFloat(cs.top) === 0) {
        el.style.setProperty('top', h + 'px', 'important');
        el.setAttribute('data-kpb-shifted', '');
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', shift); else shift();
  window.addEventListener('load', shift);
})();
</script>
HTML;
    }

    /**
     * Il popup che chiede all'admin di assumersi la responsabilita del codice
     * in attesa. Funziona senza JavaScript: un modulo che va all'editor (con
     * il token CSRF della sessione) e un link per guardare senza script.
     */
    private static function approvalDialog(Staging $staging, string $editorUrl): string
    {
        $fingerprint = $staging->codeFingerprint();
        $csrf = (string) ($_SESSION['kris_csrf'] ?? '');
        if ($fingerprint === null) return '';
        $files = '';
        foreach ($staging->codeFiles() as $file) {
            $files .= '<li>' . htmlspecialchars($file, ENT_QUOTES) . '</li>';
        }
        $action = htmlspecialchars($editorUrl, ENT_QUOTES);
        $return = htmlspecialchars(self::currentQuery(['kris_preview_noscript' => null, 'kris_preview_approve' => null]), ENT_QUOTES);
        $noScript = htmlspecialchars(self::currentUrl(['kris_preview_noscript' => '1', 'kris_preview_approve' => null]), ENT_QUOTES);
        $fp = htmlspecialchars($fingerprint, ENT_QUOTES);
        $token = htmlspecialchars($csrf, ENT_QUOTES);
        $form = $csrf === ''
            ? '<p>Per approvarli apri <a href="' . $action . '">Rivedi e pubblica</a>.</p><div class="kca-actions"><a href="' . $noScript . '">Guarda senza script</a></div>'
            : <<<HTML
<form method="POST" action="{$action}">
<input type="hidden" name="csrf" value="{$token}"><input type="hidden" name="approve_code" value="{$fp}"><input type="hidden" name="return" value="{$return}">
<label><input type="checkbox" required> <span>Ho controllato questi file. Mi assumo la responsabilità del codice e delle azioni che esegue.</span></label>
<div class="kca-actions"><a href="{$noScript}">Guarda senza script</a><button type="submit">Approva ed esegui gli script</button></div>
</form>
HTML;
        return <<<HTML
<div id="kris-code-approval" role="dialog" aria-modal="true" aria-labelledby="kca-title">
<div class="kca-box">
<h2 id="kca-title">Script in attesa di approvazione</h2>
<p>Le modifiche in attesa aggiungono codice che il browser esegue:</p>
<ul>{$files}</ul>
<p>Questo codice gira con il tuo accesso all'editor ancora aperto: potrebbe agire a tuo nome, anche solo aprendo l'anteprima. Finché non lo approvi, l'anteprima non esegue gli script del sito. Se non sai cosa fanno questi file, controllali in «Rivedi e pubblica» prima di approvarli.</p>
{$form}
</div>
</div>
HTML;
    }

    /** La query della pagina corrente in anteprima, con i parametri indicati cambiati (null = tolto). */
    private static function currentQuery(array $changes): string
    {
        $query = array_merge($_GET, $changes);
        unset($query['kris_preview_asset']);
        $query = array_filter($query, fn($v) => $v !== null);
        $query['kris_preview'] = '1';
        return http_build_query($query);
    }

    private static function currentUrl(array $changes): string
    {
        return 'index.php?' . self::currentQuery($changes);
    }
}
