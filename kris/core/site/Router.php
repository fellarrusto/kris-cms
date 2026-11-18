<?php
declare(strict_types=1);

namespace Kris\Site;

/**
 * Indirizzi leggibili: /menu, /progetto/3, /en/menu.
 *
 * Le rotte si ricavano dalle regole della sitemap (config/seo.json), che i
 * siti hanno già: una regola con "id" è una pagina fissa, senza "id" una
 * pagina per ogni contenuto della raccolta, con "children" una pagina per
 * ogni elemento dell'elenco. Una pagina senza regola rende il contenuto 0
 * della raccolta con il suo nome, o della homepage.
 *
 * Quello che una rotta non sa esprimere resta un indirizzo index.php?…, che
 * continua a funzionare.
 */
final class Router
{
    /** @return array<string, array{key: string, kind: string, id: int, children: string}> */
    public static function routes(array $pages, array $sitemap, array $model): array
    {
        $routes = [];
        foreach ($sitemap as $rule) {
            if (!is_array($rule) || !is_string($rule['page'] ?? null) || isset($routes[$rule['page']])) continue;
            $key = is_string($rule['key'] ?? null) ? $rule['key'] : 'homepage';
            $kind = is_string($rule['children'] ?? null) && $rule['children'] !== '' ? 'children' : (array_key_exists('id', $rule) || $key === 'homepage' ? 'single' : 'collection');
            $routes[$rule['page']] = ['key' => $key, 'kind' => $kind, 'id' => (int) ($rule['id'] ?? 0), 'children' => (string) ($rule['children'] ?? '')];
        }
        foreach ($pages as $page) {
            if (!is_string($page) || isset($routes[$page])) continue;
            $routes[$page] = ['key' => isset($model[$page]) ? $page : 'homepage', 'kind' => 'single', 'id' => 0, 'children' => ''];
        }
        return array_intersect_key($routes, array_flip(array_filter($pages, 'is_string')));
    }

    /** Il percorso leggibile di una pagina ("/", "/en/progetto/3"), o null se la rotta non lo sa esprimere. */
    public static function path(array $routes, string $page, string $key, int $id, array $path, string $lang, string $defaultLang): ?string
    {
        $route = $routes[$page] ?? null;
        if ($route === null || $route['key'] !== $key) return null;
        $prefix = $lang === $defaultLang ? '' : '/' . rawurlencode($lang);
        $base = $page === 'homepage' ? '' : '/' . rawurlencode($page);
        $path = array_values(array_map('strval', $path));
        $out = match ($route['kind']) {
            'single' => $id === $route['id'] && !$path ? $base : null,
            'collection' => !$path ? "{$base}/{$id}" : null,
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
        if (count($rest) !== 1 || !ctype_digit($rest[0])) return null;
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
        return (string) preg_replace_callback(
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
    }

    /** La cartella pubblica del sito, senza barra finale: "" alla radice del dominio, "/sito" in una sottocartella. */
    public static function basePath(array $server): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/')));
        return preg_match('#^/[A-Za-z0-9._~/-]*$#', $dir) ? rtrim($dir, '/') : '';
    }
}
