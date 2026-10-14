<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Anteprima dello staging sul sito pubblico, solo per chi ha una sessione
 * dell'editor. Si chiede con ?kris_preview=1; senza sessione il parametro e
 * ignorato e si vede il sito normale.
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

    /** Invia un foglio di stile dello staging, oppure risponde 404. */
    public static function serveAsset(Staging $staging, string $asset): void
    {
        $file = $staging->dir() . '/assets/' . $asset;
        if (preg_match('#^css/[a-z0-9][a-z0-9_-]*\.css$#', $asset) !== 1 || !is_file($file)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: text/css; charset=utf-8');
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
     * anteprima, barra con i comandi in fondo al body.
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

        $bar = self::bar($editorUrl, $exitUrl);
        $html = (string) preg_replace('#<head(\s[^>]*)?>#i', "\$0\n<meta name=\"robots\" content=\"noindex\">", $html, 1);
        return stripos($html, '</body>') !== false
            ? (string) preg_replace('#</body>#i', $bar . "\n</body>", $html, 1)
            : $html . $bar;
    }

    /** La stessa pagina senza anteprima. */
    public static function exitUrl(array $query): string
    {
        unset($query['kris_preview'], $query['kris_preview_asset']);
        return 'index.php' . ($query ? '?' . http_build_query($query) : '');
    }

    /** Barra fissa con stili propri, per non dipendere dal tema del sito. */
    private static function bar(string $editorUrl, string $exitUrl): string
    {
        $url = htmlspecialchars($editorUrl, ENT_QUOTES);
        $exit = htmlspecialchars($exitUrl, ENT_QUOTES);
        return <<<HTML
<div id="kris-preview-bar" role="region" aria-label="Anteprima delle modifiche" style="position:fixed;left:0;right:0;bottom:0;z-index:2147483647;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;padding:10px 16px;background:#202b27;color:#fff;font:14px/1.4 'Segoe UI',system-ui,sans-serif;box-shadow:0 -2px 12px rgba(0,0,0,.25)">
<span><strong style="color:#b7d6a6">Anteprima</strong> · stai vedendo le modifiche non ancora pubblicate. I visitatori vedono ancora il sito attuale.</span>
<span style="display:flex;gap:8px;flex-wrap:wrap"><a href="{$url}" style="color:#202b27;background:#b7d6a6;padding:6px 12px;border-radius:6px;text-decoration:none;font-weight:600">Rivedi e pubblica</a><a href="{$exit}" style="color:#fff;padding:6px 12px;border:1px solid #63716a;border-radius:6px;text-decoration:none">Esci dall'anteprima</a></span>
</div>
HTML;
    }
}
