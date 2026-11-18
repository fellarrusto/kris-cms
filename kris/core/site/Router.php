<?php
declare(strict_types=1);

namespace Kris\Site;

/**
 * Indirizzi leggibili: /menu, /progetto/3, /en/menu.
 *
 * Le rotte si ricavano dalle pagine dello schema (formato 2): una pagina con
 * "item" rende sempre quel contenuto, senza "item" un contenuto del tipo per
 * id (o per slug), con "list" un elemento dell'elenco di quel contenuto.
 *
 * Quello che una rotta non sa esprimere resta un indirizzo index.php?…, che
 * continua a funzionare.
 */
final class Router
{
    /** @return array<string, array{key: string, kind: string, id: int, children: string}> */
    public static function routes(array $schema): array
    {
        $routes = [];
        foreach (is_array($schema['pages'] ?? null) ? $schema['pages'] : [] as $page => $p) {
            if (!is_string($page) || !is_array($p)) continue;
            $key = is_string($p['type'] ?? null) ? $p['type'] : 'homepage';
            $kind = is_string($p['list'] ?? null) && $p['list'] !== '' ? 'children' : (array_key_exists('item', $p) ? 'single' : 'collection');
            $routes[$page] = ['key' => $key, 'kind' => $kind, 'id' => (int) ($p['item'] ?? 0), 'children' => (string) ($p['list'] ?? '')]
                // "slug": il campo dei contenuti che dà l'indirizzo (/progetto/casa-rossi); vuoto = l'id
                + ($kind === 'collection' && is_string($p['slug'] ?? null) ? ['slug' => $p['slug']] : []);
            // Un elenco promosso a tipo: i vecchi link (key=homepage&id=0&path=posts/3) portano qui
            foreach (is_array($schema['promoted'] ?? null) ? $schema['promoted'] : [] as $from) {
                if ($kind === 'collection' && ($from['to'] ?? null) === $key) $routes[$page]['from'] = [(string) $from['from'], (int) $from['id'], (string) $from['list']];
            }
        }
        return $routes;
    }

    /** Il percorso leggibile di una pagina ("/", "/en/progetto/3"), o null se la rotta non lo sa esprimere. */
    public static function path(array $routes, string $page, string $key, int $id, array $path, string $lang, string $defaultLang): ?string
    {
        $route = $routes[$page] ?? null;
        $path = array_values(array_map('strval', $path));
        if ($route !== null && isset($route['from']) && count($path) === 2 && [$key, $id, $path[0]] === $route['from'] && ctype_digit($path[1])) {
            [$key, $id, $path] = [$route['key'], (int) $path[1], []];
        }
        if ($route === null || $route['key'] !== $key) return null;
        $prefix = $lang === $defaultLang ? '' : '/' . rawurlencode($lang);
        $base = $page === 'homepage' ? '' : '/' . rawurlencode($page);
        $out = match ($route['kind']) {
            'single' => $id === $route['id'] && !$path ? $base : null,
            'collection' => !$path ? "{$base}/" . (self::slugOf($route, $id, $lang) ?? $id) : null,
            'children' => $id === $route['id'] && count($path) === 2 && $path[0] === $route['children'] && ctype_digit($path[1]) ? "{$base}/{$path[1]}" : null,
            default => null,
        };
        if ($out === null) return null;
        return ($prefix . $out) === '' ? '/' : $prefix . ($out === '' ? '/' : $out);
    }

    /**
     * La pagina chiesta da un percorso leggibile, come parametri di index.php.
     *
     * @return array<string, string>|null null se il percorso non è di nessuna pagina
     */
    public static function resolve(array $routes, string $requestPath, array $langs, string $defaultLang): ?array
    {
        $segments = array_values(array_filter(explode('/', $requestPath), fn($s) => $s !== ''));
        $segments = array_map('rawurldecode', $segments);
        $query = [];
        if ($segments && $segments[0] !== $defaultLang && in_array($segments[0], $langs, true)) {
            $query['ln'] = array_shift($segments);
        }
        if (!$segments) return isset($routes['homepage']) ? $query + ['page' => 'homepage'] : null;
        $page = $segments[0];
        $route = $routes[$page] ?? null;
        if ($route === null || $page === 'homepage') return null;
        $query += ['page' => $page, 'key' => $route['key']];
        $rest = array_slice($segments, 1);
        if ($route['kind'] === 'single') {
            return $rest ? null : $query + ['id' => (string) $route['id']];
        }
        if (count($rest) !== 1) return null;
        if ($route['kind'] === 'collection' && !ctype_digit($rest[0])) {
            // Uno slug: si cerca fra quelli dei contenuti, nella lingua della richiesta
            foreach (array_keys($route['slugs'] ?? []) as $id) {
                if (self::slugOf($route, (int) $id, $query['ln'] ?? $defaultLang) === $rest[0]) return $query + ['id' => (string) $id];
            }
            return null;
        }
        if (!ctype_digit($rest[0])) return null;
        return $route['kind'] === 'collection'
            ? $query + ['id' => $rest[0]]
            : $query + ['id' => (string) $route['id'], 'path' => $route['children'] . '/' . $rest[0]];
    }

    /**
     * Link e risorse di una pagina resa con indirizzi leggibili: i link
     * index.php?page=… diventano percorsi leggibili, e assets/, kris/,
     * index.php partono dalla cartella del sito (una pagina /en/menu li
     * cercherebbe altrimenti in /en/). Ancore, indirizzi assoluti e quelli
     * da / non si toccano.
     */
    public static function rewriteLinks(string $html, array $routes, string $basePath, string $defaultLang): string
    {
        $html = (string) preg_replace_callback(
            '#\b(href|src|poster|action)=(["\'])(\./)?((?:assets|kris)/[^"\']*|(?:index\.php)?\?[^"\'\#]*(?:\#[^"\']*)?|index\.php(?:\#[^"\']*)?|sitemap\.php)\2#i',
            function (array $m) use ($routes, $basePath, $defaultLang): string {
                $url = $m[4];
                // "?page=…" vale index.php?page=…: sotto /en/menu andrebbe al percorso sbagliato
                if (str_starts_with($url, '?')) $url = 'index.php' . $url;
                if (preg_match('#^index\.php(?:\?([^\#]*))?(\#.*)?$#i', $url, $q) === 1) {
                    parse_str(html_entity_decode($q[1] ?? '', ENT_QUOTES | ENT_HTML5), $params);
                    // Solo i parametri di una pagina: altri parametri (anteprima, campagne) restano in index.php?…
                    if (!array_diff(array_keys($params), ['page', 'key', 'id', 'path', 'ln']) && is_array($params) && !array_filter($params, 'is_array')) {
                        $pretty = self::path(
                            $routes,
                            (string) ($params['page'] ?? 'homepage'),
                            (string) ($params['key'] ?? 'homepage'),
                            (int) ($params['id'] ?? 0),
                            ($params['path'] ?? '') === '' ? [] : explode('/', (string) $params['path']),
                            (string) ($params['ln'] ?? $defaultLang),
                            $defaultLang
                        );
                        if ($pretty !== null) {
                            return $m[1] . '=' . $m[2] . htmlspecialchars($basePath . $pretty . ($q[2] ?? ''), ENT_QUOTES, 'UTF-8') . $m[2];
                        }
                    }
                }
                return $m[1] . '=' . $m[2] . $basePath . '/' . $url . $m[2];
            },
            $html
        );
        // srcset: ogni indirizzo della lista che inizia con assets/
        return (string) preg_replace_callback('#\bsrcset=(["\'])([^"\']*)\1#i', fn(array $m): string => 'srcset=' . $m[1]
            . preg_replace('#(^|,\s*)(?:\./)?(assets/)#', '$1' . $basePath . '/$2', $m[2]) . $m[1], $html);
    }

    /**
     * Lo slug di un contenuto in una lingua, o null (vale l'id). Due contenuti
     * con lo stesso slug: dal secondo in poi si aggiunge -id.
     * shortcut: niente storico degli slug, un indirizzo vecchio dopo una rinomina risponde 404; aggiungerlo con la tabella dei reindirizzamenti dell'editor.
     */
    private static function slugOf(array $route, int $id, string $lang): ?string
    {
        $slug = $route['slugs'][$id][$lang] ?? '';
        if ($slug === '') return null;
        foreach ($route['slugs'] as $other => $langs) {
            if ((int) $other === $id) break;
            if (($langs[$lang] ?? '') === $slug) return $slug . '-' . $id;
        }
        return $slug;
    }

    /** Un testo come segmento di indirizzo: "Casa Rossi, è qui" → "casa-rossi-e-qui". */
    public static function slug(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
        // Solo cifre si confonderebbe con un id
        return ctype_digit($slug) ? '' : substr($slug, 0, 80);
    }

    /** La cartella pubblica del sito, senza barra finale: "" alla radice del dominio, "/sito" in una sottocartella. */
    public static function basePath(array $server): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/')));
        return preg_match('#^/[A-Za-z0-9._~/-]*$#', $dir) ? rtrim($dir, '/') : '';
    }
}
