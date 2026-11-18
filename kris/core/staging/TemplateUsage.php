<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Quali contenuti rende ogni template, ricavato dai template stessi.
 *
 * Un contesto e il nome di una raccolta ("torte") oppure una raccolta
 * seguita dagli elenchi attraversati ("homepage/features/highlights"): e lo
 * schema da cui il template legge i suoi {{campi}}. Le fonti sono:
 *  - le regole della sitemap e i link "page=…&key=…" (path=elenco/ID) per i
 *    template delle pagine; la pagina homepage senza altre indicazioni rende
 *    la raccolta homepage, come l'indirizzo predefinito;
 *  - k-component="raccolta" k-template="frammento";
 *  - k-array="elenco" k-template="frammento", dal contesto del template che
 *    lo contiene: un elenco del contesto, altrimenti la raccolta omonima.
 *
 * Un template per cui non si trova nessuna fonte ha contesto sconosciuto:
 * chi controlla i campi deve trattarlo come se potesse rendere qualunque
 * raccolta.
 */
final class TemplateUsage
{
    /**
     * @param array<string, string> $templates nome del template (senza .html) => HTML
     * @param list<array> $models schemi (k_model) in cui cercare gli elenchi: sito e anteprima
     * @param list<array> $sitemap regole della sitemap (config/seo.json)
     * @return array<string, ?list<string>> nome del template => contesti, null se sconosciuti
     */
    public static function contexts(array $templates, array $models, array $sitemap): array
    {
        $found = [];
        $add = function (string $template, string $context) use (&$found, $templates): bool {
            if (!isset($templates[$template]) || isset($found[$template][$context])) return false;
            $found[$template][$context] = true;
            return true;
        };

        foreach ($sitemap as $rule) {
            if (!is_array($rule) || !is_string($rule['page'] ?? null)) continue;
            $key = is_string($rule['key'] ?? null) ? $rule['key'] : 'homepage';
            $add($rule['page'], is_string($rule['children'] ?? null) && $rule['children'] !== '' ? "{$key}/{$rule['children']}" : $key);
        }
        foreach ($templates as $html) {
            foreach (self::pageLinks($html) as [$page, $context]) $add($page, $context);
        }
        if (isset($templates['homepage']) && !isset($found['homepage'])) $add('homepage', 'homepage');

        // Componenti ed elenchi: si propagano finche si trova qualcosa di nuovo
        do {
            $changed = false;
            foreach ($templates as $name => $html) {
                foreach (self::placeholders($html) as [$kind, $target, $fragment]) {
                    if ($kind === 'component') {
                        $changed = $add($fragment, $target) || $changed;
                        continue;
                    }
                    foreach (array_keys($found[$name] ?? []) as $context) {
                        $inner = self::hasList($models, "{$context}/{$target}") ? "{$context}/{$target}" : $target;
                        $changed = $add($fragment, (string) $inner) || $changed;
                    }
                }
            }
        } while ($changed);

        $result = [];
        foreach (array_keys($templates) as $name) {
            $result[$name] = isset($found[$name]) ? array_keys($found[$name]) : null;
        }
        return $result;
    }

    /** Contesto di un campo: la raccolta seguita dagli elenchi che lo contengono. */
    public static function context(string $collection, array $path): string
    {
        return implode('/', array_merge([$collection], array_map('strval', $path)));
    }

    /** @return list<array{0: string, 1: string}> pagina e contesto dei link "page=…" di un template */
    private static function pageLinks(string $html): array
    {
        $links = [];
        if (preg_match_all('/[?&;]page=([a-z0-9][a-z0-9_-]*)[^"\'\s<>]*/i', $html, $matches, PREG_SET_ORDER) === false) return [];
        foreach ($matches as $m) {
            $query = str_replace('&amp;', '&', $m[0]);
            $key = preg_match('/[?&;]key=([a-z0-9_]+)(?=[&#]|$)/i', $query, $k) === 1 ? $k[1] : 'homepage';
            $context = $key;
            if (preg_match('/[?&;]path=([^&#]+)/i', $query, $p) === 1) {
                $parts = array_values(array_filter(explode('/', rawurldecode($p[1])), fn($s) => $s !== ''));
                for ($i = 0; $i < count($parts); $i += 2) {
                    // Un {{campo}} al posto del nome dell'elenco: il contesto non si ricava
                    if (!preg_match('/^[a-z0-9_]+$/', $parts[$i])) continue 2;
                    $context .= '/' . $parts[$i];
                }
            }
            if (!preg_match('/^[a-z0-9_]+$/', $key)) continue;
            $links[] = [$m[1], $context];
        }
        return $links;
    }

    /** @return list<array{0: 'component'|'array', 1: string, 2: string}> segnaposto con il frammento che usano */
    private static function placeholders(string $html): array
    {
        $found = [];
        if (preg_match_all('/<[a-z][a-z0-9-]*\b[^>]*>/i', $html, $tags) === false) return [];
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bk-template=["\']([^"\']+)["\']/i', $tag, $t) !== 1) continue;
            if (preg_match('/\bk-component=["\']([a-z0-9_]+)["\']/i', $tag, $c) === 1) {
                $found[] = ['component', $c[1], $t[1]];
            } elseif (preg_match('/\bk-array=["\']([a-z0-9_]+)["\']/i', $tag, $a) === 1) {
                $found[] = ['array', $a[1], $t[1]];
            }
        }
        return $found;
    }

    /** Vero se in uno degli schemi il contesto indicato e un elenco. */
    private static function hasList(array $models, string $context): bool
    {
        $parts = explode('/', $context);
        foreach ($models as $model) {
            $schema = $model[$parts[0]] ?? null;
            for ($i = 1; is_array($schema) && $i < count($parts); $i++) {
                $def = null;
                foreach ($schema as $field) {
                    if (is_array($field) && ($field['name'] ?? null) === $parts[$i]) $def = $field;
                }
                $schema = is_array($def) && ($def['type'] ?? null) === 'array' ? ($def['of'] ?? []) : null;
            }
            if (is_array($schema)) return true;
        }
        return false;
    }
}
