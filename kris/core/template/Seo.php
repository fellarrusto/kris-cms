<?php
declare(strict_types=1);

namespace Kris\Template;

/**
 * Indirizzi canonici, alternative per lingua e sitemap.
 *
 * Configurazione opzionale in config/seo.json:
 *
 *   {
 *     "base_url": "https://www.esempio.it",   indirizzo pubblico del sito
 *     "head": true,                            canonical e hreflang automatici
 *     "sitemap": [                             pagine da elencare
 *       {"page": "homepage"},
 *       {"page": "progetto", "key": "progetti"},                          ogni entita della raccolta
 *       {"page": "post", "key": "homepage", "id": 0, "children": "posts"} ogni elemento della lista
 *     ]
 *   }
 *
 * Senza base_url l'indirizzo si ricava dalla richiesta: va bene in sviluppo,
 * ma in produzione conviene dichiararlo (l'host della richiesta lo decide chi
 * la invia). Senza "sitemap" si elenca la sola homepage.
 *
 * Gli URL omettono i parametri con il valore predefinito di index.php
 * (page e key "homepage", id 0, lingua principale), cosi ogni pagina ha un
 * solo indirizzo canonico.
 */
final class Seo
{
    public static function config(string $root): array
    {
        $file = $root . '/config/seo.json';
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $config = is_array($config) ? $config : [];
        $sitemap = $config['sitemap'] ?? null;
        return [
            'base_url' => is_string($config['base_url'] ?? null) ? rtrim($config['base_url'], '/') : null,
            'head' => ($config['head'] ?? true) !== false,
            'sitemap' => is_array($sitemap) ? $sitemap : [['page' => 'homepage']],
        ];
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
    public static function url(string $base, string $page, string $key, int $id, array $path, string $lang, string $defaultLang): string
    {
        $query = [];
        if ($page !== 'homepage') $query[] = 'page=' . rawurlencode($page);
        if ($key !== 'homepage') $query[] = 'key=' . rawurlencode($key);
        if ($id !== 0) $query[] = 'id=' . $id;
        if ($path) $query[] = 'path=' . implode('/', array_map('rawurlencode', $path));
        if ($lang !== $defaultLang) $query[] = 'ln=' . rawurlencode($lang);
        return $base . '/' . ($query ? '?' . implode('&', $query) : '');
    }

    /** Tag <link> canonical e hreflang per la pagina corrente. */
    public static function headTags(string $base, string $page, string $key, int $id, array $path, string $lang, array $langs): string
    {
        $default = (string) ($langs[0] ?? $lang);
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $tags = '<link rel="canonical" href="' . $esc(self::url($base, $page, $key, $id, $path, $lang, $default)) . '">';
        if (count($langs) > 1) {
            foreach ($langs as $l) {
                $tags .= "\n" . '<link rel="alternate" hreflang="' . $esc((string) $l) . '" href="'
                    . $esc(self::url($base, $page, $key, $id, $path, (string) $l, $default)) . '">';
            }
            $tags .= "\n" . '<link rel="alternate" hreflang="x-default" href="'
                . $esc(self::url($base, $page, $key, $id, $path, $default, $default)) . '">';
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

    /**
     * Pagine da elencare nella sitemap: [page, key, id, path].
     * Si scartano le voci con pagine non autorizzate o contenuti inesistenti.
     */
    public static function sitemapEntries(array $data, array $sitemap, array $allowedPages): array
    {
        $entries = [];
        foreach ($sitemap as $rule) {
            if (!is_array($rule) || !is_string($rule['page'] ?? null) || !in_array($rule['page'], $allowedPages, true)) {
                continue;
            }
            $page = $rule['page'];
            $key = is_string($rule['key'] ?? null) ? $rule['key'] : 'homepage';
            $roots = array_values(array_filter($data, fn($e) => is_array($e) && ($e['name'] ?? null) === $key));

            if (!array_key_exists('id', $rule) && !isset($rule['children'])) {
                foreach ($roots as $entity) {
                    $entries[] = [$page, $key, (int) ($entity['id'] ?? 0), []];
                }
                continue;
            }
            $id = (int) ($rule['id'] ?? 0);
            $entity = null;
            foreach ($roots as $candidate) {
                if ((int) ($candidate['id'] ?? -1) === $id) {
                    $entity = $candidate;
                    break;
                }
            }
            if ($entity === null) {
                continue;
            }
            if (!is_string($rule['children'] ?? null)) {
                $entries[] = [$page, $key, $id, []];
                continue;
            }
            foreach (is_array($entity['data'] ?? null) ? $entity['data'] : [] as $field) {
                if (($field['name'] ?? null) === $rule['children'] && ($field['type'] ?? null) === 'array') {
                    foreach (is_array($field['value'] ?? null) ? $field['value'] : [] as $child) {
                        $entries[] = [$page, $key, $id, [$rule['children'], (string) (int) ($child['id'] ?? 0)]];
                    }
                }
            }
        }
        return $entries;
    }

    /** Documento sitemap XML, con le alternative per lingua. */
    public static function sitemapXml(array $entries, string $base, array $langs): string
    {
        $default = (string) ($langs[0] ?? 'it');
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($entries as [$page, $key, $id, $path]) {
            foreach ($langs as $lang) {
                $xml .= '  <url>' . "\n" . '    <loc>' . $esc(self::url($base, $page, $key, $id, $path, (string) $lang, $default)) . '</loc>' . "\n";
                if (count($langs) > 1) {
                    foreach ($langs as $alt) {
                        $xml .= '    <xhtml:link rel="alternate" hreflang="' . $esc((string) $alt) . '" href="'
                            . $esc(self::url($base, $page, $key, $id, $path, (string) $alt, $default)) . '"/>' . "\n";
                    }
                }
                $xml .= '  </url>' . "\n";
            }
        }
        return $xml . '</urlset>' . "\n";
    }
}
