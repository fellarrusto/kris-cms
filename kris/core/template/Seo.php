<?php
declare(strict_types=1);

namespace Kris\Template;

use Kris\Entity\Entity;

/**
 * Indirizzi canonici, alternative per lingua e sitemap.
 *
 * Le opzioni stanno in settings.seo (formato 2): base_url (indirizzo
 * pubblico del sito), head (canonical e hreflang automatici, predefinito
 * true), pretty_urls, auto_language, robots. I reindirizzamenti in
 * schema.redirects, le pagine della sitemap in schema.pages ("sitemap": true).
 *
 * Senza base_url l'indirizzo si ricava dalla richiesta: va bene in sviluppo,
 * ma in produzione conviene dichiararlo (l'host della richiesta lo decide chi
 * la invia).
 *
 * Gli URL omettono i parametri con il valore predefinito di index.php
 * (page e key "homepage", id 0, lingua principale), cosi ogni pagina ha un
 * solo indirizzo canonico.
 */
final class Seo
{
    public static function config(string $root): array
    {
        try {
            $site = \Kris\Store\Site::load($root);
        } catch (\Kris\Entity\StorageException $e) {
            // Archivio rovinato: valori predefiniti, la pagina si rende comunque
            $site = ['schema' => [], 'settings' => []];
        }
        $config = is_array($site['settings']['seo'] ?? null) ? $site['settings']['seo'] : [];
        $redirects = $site['schema']['redirects'] ?? [];
        return [
            'base_url' => is_string($config['base_url'] ?? null) ? rtrim($config['base_url'], '/') : null,
            'head' => ($config['head'] ?? true) !== false,
            // Indirizzi leggibili (/menu, /en/progetto/3) sempre, dalla 2.0: i vecchi index.php?… rispondono con un 301.
            // La chiave pretty_urls dei siti 1.x non conta più. Unica eccezione: su Apache senza la regola del
            // .htaccess di Kris i percorsi darebbero 404, quindi restano gli indirizzi di prima (e l'editor lo segnala).
            'pretty_urls' => self::rewriteAvailable($root),
            // Lingua automatica: la homepage senza lingua nell'indirizzo segue il browser del visitatore
            'auto_language' => ($config['auto_language'] ?? false) === true,
            // Reindirizzamenti 301: {"/vecchio": "/nuovo"}, percorsi dalla cartella del sito o indirizzi https
            'redirects' => array_filter(is_array($redirects) ? $redirects : [], fn($to, $from) => is_string($from) && str_starts_with($from, '/')
                && is_string($to) && (str_starts_with($to, '/') || preg_match('#^https?://#', $to) === 1), ARRAY_FILTER_USE_BOTH),
            // Righe aggiuntive di robots.txt
            'robots' => is_string($config['robots'] ?? null) ? $config['robots'] : '',
        ];
    }

    /**
     * Vero se il server passa a Kris i percorsi che non sono file: su Apache e
     * LiteSpeed serve la regola del .htaccess di Kris nella cartella del sito;
     * sugli altri server (Nginx, il server di sviluppo di PHP) si presume
     * configurato, come chiede AGENTS.md.
     */
    public static function rewriteAvailable(string $root): bool
    {
        $software = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (stripos($software, 'apache') === false && stripos($software, 'litespeed') === false) return true;
        static $cache = [];
        return $cache[$root] ??= preg_match('/index\.php \[L/', (string) @file_get_contents(rtrim($root, '/\\') . '/.htaccess')) === 1;
    }

    /** Indirizzo pubblico della cartella del sito, senza barra finale. */
    public static function baseUrl(array $config, array $server): string
    {
        if ($config['base_url'] !== null) {
            return $config['base_url'];
        }
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = strtolower((string) ($server['HTTP_HOST'] ?? 'localhost'));
        if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) {
            $host = 'localhost';
        }
        $dir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/')));
        // Solo un percorso URL: da riga di comando SCRIPT_NAME e un file su disco.
        $dir = preg_match('#^/[A-Za-z0-9._~/-]*$#', $dir) ? rtrim($dir, '/') : '';
        return ($https ? 'https' : 'http') . '://' . $host . $dir;
    }

    /** URL di una pagina, con i soli parametri diversi dal valore predefinito. */
    public static function url(string $base, string $page, string $key, int $id, array $path, string $lang, string $defaultLang, ?array $routes = null): string
    {
        if ($routes !== null) {
            $pretty = \Kris\Site\Router::path($routes, $page, $key, $id, $path, $lang, $defaultLang);
            if ($pretty !== null) return $base . $pretty;
        }
        $query = [];
        if ($page !== 'homepage') $query[] = 'page=' . rawurlencode($page);
        if ($key !== 'homepage') $query[] = 'key=' . rawurlencode($key);
        if ($id !== 0) $query[] = 'id=' . $id;
        if ($path) $query[] = 'path=' . implode('/', array_map('rawurlencode', $path));
        if ($lang !== $defaultLang) $query[] = 'ln=' . rawurlencode($lang);
        return $base . '/' . ($query ? '?' . implode('&', $query) : '');
    }

    /** Tag <link> canonical e hreflang per la pagina corrente. */
    public static function headTags(string $base, string $page, string $key, int $id, array $path, string $lang, array $langs, ?array $routes = null): string
    {
        $default = (string) ($langs[0] ?? $lang);
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $tags = '<link rel="canonical" href="' . $esc(self::url($base, $page, $key, $id, $path, $lang, $default, $routes)) . '">';
        if (count($langs) > 1) {
            foreach ($langs as $l) {
                $tags .= "\n" . '<link rel="alternate" hreflang="' . $esc((string) $l) . '" href="'
                    . $esc(self::url($base, $page, $key, $id, $path, (string) $l, $default, $routes)) . '">';
            }
            $tags .= "\n" . '<link rel="alternate" hreflang="x-default" href="'
                . $esc(self::url($base, $page, $key, $id, $path, $default, $default, $routes)) . '">';
        }
        return $tags;
    }

    /** Inserisce i tag prima di </head>, a meno che il template abbia gia un canonical. */
    public static function inject(string $html, string $tags): string
    {
        if (preg_match('/<link\b[^>]*\brel\s*=\s*["\']?canonical/i', $html)) {
            return $html;
        }
        $pos = stripos($html, '</head>');
        return $pos === false ? $html : substr($html, 0, $pos) . $tags . "\n" . substr($html, $pos);
    }

    /** Tipi delle immagini caricabili come favicon, per l'attributo type. */
    private const ICON_TYPES = [
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    ];

    /**
     * Inserisce la favicon prima di </head>, a meno che il template ne abbia
     * gia una. Si accettano solo file caricati dal CMS (assets/uploads/).
     */
    public static function injectIcon(string $html, ?string $url): string
    {
        if (!is_string($url) || preg_match('#^assets/uploads/[A-Za-z0-9][A-Za-z0-9._-]*\.([A-Za-z0-9]+)$#', $url, $m) !== 1) {
            return $html;
        }
        $type = self::ICON_TYPES[strtolower($m[1])] ?? null;
        if ($type === null || preg_match('/<link\b[^>]*\brel\s*=\s*["\']?(?:shortcut\s+)?icon\b/i', $html)) {
            return $html;
        }
        $pos = stripos($html, '</head>');
        if ($pos === false) {
            return $html;
        }
        $tag = '<link rel="icon" type="' . $type . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        return substr($html, 0, $pos) . $tag . "\n" . substr($html, $pos);
    }

    /**
     * Pagine da elencare nella sitemap: [page, key, id, path, priority?].
     * Solo le pagine con "sitemap": true e i contenuti visibili: con "item"
     * quel contenuto, senza ogni contenuto del tipo, con "list" ogni elemento
     * dell'elenco.
     */
    public static function sitemapEntries(array $schema, array $content): array
    {
        $entries = [];
        foreach (is_array($schema['pages'] ?? null) ? $schema['pages'] : [] as $page => $p) {
            if (!is_string($page) || !is_array($p) || ($p['sitemap'] ?? false) !== true) continue;
            $key = is_string($p['type'] ?? null) ? $p['type'] : 'homepage';
            // "priority" (0–1) facoltativa: va in fondo alla voce solo se c'è
            $extra = is_numeric($p['priority'] ?? null) ? [max(0.0, min(1.0, (float) $p['priority']))] : [];
            $items = array_values(array_filter($content, fn($i) => is_array($i) && ($i['type'] ?? null) === $key && !Entity::isHidden($i)));
            if (!array_key_exists('item', $p)) {
                foreach ($items as $item) $entries[] = array_merge([$page, $key, (int) ($item['id'] ?? 0), []], $extra);
                continue;
            }
            $id = (int) $p['item'];
            $item = array_values(array_filter($items, fn($i) => (int) ($i['id'] ?? -1) === $id))[0] ?? null;
            if ($item === null) continue;
            $list = is_string($p['list'] ?? null) && $p['list'] !== '' ? $p['list'] : null;
            if ($list === null) {
                $entries[] = array_merge([$page, $key, $id, []], $extra);
                continue;
            }
            foreach (is_array($item['values'][$list] ?? null) ? $item['values'][$list] : [] as $child) {
                if (!is_array($child) || Entity::isHidden($child)) continue;
                $entries[] = array_merge([$page, $key, $id, [$list, (string) (int) ($child['id'] ?? 0)]], $extra);
            }
        }
        return $entries;
    }
    /** Documento sitemap XML, con le alternative per lingua. */
    public static function sitemapXml(array $entries, string $base, array $langs, ?array $routes = null): string
    {
        $default = (string) ($langs[0] ?? 'it');
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($entries as $entry) {
            [$page, $key, $id, $path] = $entry;
            foreach ($langs as $lang) {
                $xml .= '  <url>' . "\n" . '    <loc>' . $esc(self::url($base, $page, $key, $id, $path, (string) $lang, $default, $routes)) . '</loc>' . "\n";
                if (isset($entry[4])) $xml .= '    <priority>' . number_format((float) $entry[4], 1, '.', '') . '</priority>' . "\n";
                if (count($langs) > 1) {
                    foreach ($langs as $alt) {
                        $xml .= '    <xhtml:link rel="alternate" hreflang="' . $esc((string) $alt) . '" href="'
                            . $esc(self::url($base, $page, $key, $id, $path, (string) $alt, $default, $routes)) . '"/>' . "\n";
                    }
                }
                $xml .= '  </url>' . "\n";
            }
        }
        return $xml . '</urlset>' . "\n";
    }
}
