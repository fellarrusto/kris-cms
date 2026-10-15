<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Anteprima dello staging sul sito pubblico, solo per chi ha una sessione
 * dell'editor. Si chiede con ?kris_preview=1; senza sessione la pagina
 * risponde 404, come se non esistesse.
 *
 * I fogli di stile modificati stanno in data/staging/, che non e pubblica:
 * li serve index.php stesso (?kris_preview_asset=css/<nome>.css), sempre solo
 * a un admin, e la pagina viene riscritta per puntare li.
 */
final class Preview
{
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

    private const UPLOAD_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'pdf' => 'application/pdf',
    ];

    /** Invia un foglio di stile o un'immagine dello staging, oppure risponde 404. */
    public static function serveAsset(Staging $staging, string $asset): void
    {
        if (preg_match('#^css/[a-z0-9][a-z0-9_-]*\.css$#', $asset) === 1) {
            $file = $staging->dir() . '/assets/' . $asset;
            $type = 'text/css; charset=utf-8';
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
        readfile($file);
    }

    /** Intestazioni di una pagina in anteprima: mai in cache, mai indicizzata. */
    public static function headers(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }

    /**
     * Pagina in anteprima: CSS dello staging, link interni che restano in
     * anteprima, barra sottile fissa in cima alla pagina (come quella di WordPress).
     */
    public static function decorate(string $html, Staging $staging, string $editorUrl, string $exitUrl): string
    {
        // assets/css/<nome>.css -> file dello staging, se e stato modificato o creato
        $html = (string) preg_replace_callback(
            '#(["\'])(?:\./)?assets/css/([a-z0-9][a-z0-9_-]*\.css)(?:\?[^"\']*)?\1#i',
            function (array $m) use ($staging): string {
                return is_file($staging->dir() . '/assets/css/' . $m[2])
                    ? $m[1] . 'index.php?kris_preview_asset=css/' . $m[2] . $m[1]
                    : $m[0];
            },
            $html
        );

        // assets/uploads/<nome> -> immagine caricata nello staging, non ancora pubblica
        $html = (string) preg_replace_callback(
            '#(["\'\s,(])(?:\./)?assets/uploads/([A-Za-z0-9][A-Za-z0-9._-]*)#',
            function (array $m) use ($staging): string {
                return is_file($staging->dir() . '/uploads/' . $m[2])
                    ? $m[1] . 'index.php?kris_preview_asset=uploads/' . $m[2]
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

        $bar = self::bar($editorUrl, $exitUrl, $staging->hasChanges());
        $html = (string) preg_replace('#<head(\s[^>]*)?>#i', "\$0\n<meta name=\"robots\" content=\"noindex\">\n" . self::BAR_STYLE, $html, 1);
        // In cima al body, prima di tutto il resto, come la barra di WordPress
        return preg_match('#<body(\s[^>]*)?>#i', $html) === 1
            ? (string) preg_replace('#<body(\s[^>]*)?>#i', "\$0\n" . $bar, $html, 1)
            : $bar . $html;
    }

    /** La stessa pagina senza anteprima. */
    public static function exitUrl(array $query): string
    {
        unset($query['kris_preview'], $query['kris_preview_asset']);
        return 'index.php' . ($query ? '?' . http_build_query($query) : '');
    }

    /**
     * Stili della barra: propri e con !important, per non dipendere dal tema
     * del sito. La pagina scende dell'altezza della barra, che resta sopra a tutto.
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
#kris-preview-bar a:focus-visible{outline:2px solid #e2b54a!important;outline-offset:1px!important}
@media(max-width:640px){#kris-preview-bar .kpb-text{display:none!important}#kris-preview-bar{justify-content:space-between!important}}
</style>
HTML;

    /** Barra sottile fissa in cima alla pagina. */
    private static function bar(string $editorUrl, string $exitUrl, bool $hasChanges): string
    {
        $url = htmlspecialchars($editorUrl, ENT_QUOTES);
        $exit = htmlspecialchars($exitUrl, ENT_QUOTES);
        $text = $hasChanges
            ? 'Stai vedendo le modifiche non ancora pubblicate. I visitatori vedono ancora il sito attuale.'
            : 'Non ci sono modifiche in attesa: stai vedendo il sito pubblicato.';
        return <<<HTML
<div id="kris-preview-bar" role="region" aria-label="Anteprima">
<span class="kpb-tag">Anteprima</span><span class="kpb-text">{$text}</span><a class="kpb-main" href="{$url}">Rivedi e pubblica</a><a href="{$exit}">Esci</a>
</div>
<script>
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
}
