<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Quali contenuti rende ogni template, ricavato dai template stessi.
 *
 * Un contesto e il nome di un tipo ("torte") oppure un tipo seguito dagli
 * elenchi attraversati ("features/highlights"): e lo schema da cui il
 * template legge i suoi {{campi}}. Le fonti sono:
 *  - le pagine dello schema (il tipo che rendono, con l'elenco se c'è) e i
 *    link "page=…&key=…" (path=elenco/ID) per i template delle pagine; un
 *    link a un elenco promosso a tipo vale il tipo nuovo; la pagina homepage
 *    senza altre indicazioni rende il tipo homepage;
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
     * @param list<array> $schemas schemi del formato 2 (tipi, pagine, elenchi promossi): sito e anteprima
     * @return array<string, ?list<string>> nome del template => contesti, null se sconosciuti
     */
    public static function contexts(array $templates, array $schemas): array
    {
        $found = [];
        $add = function (string $template, string $context) use (&$found, $templates): bool {
            if (!isset($templates[$template]) || isset($found[$template][$context])) return false;
            $found[$template][$context] = true;
            return true;
        };

        $promoted = [];
        foreach ($schemas as $schema) {
            foreach (is_array($schema['pages'] ?? null) ? $schema['pages'] : [] as $page => $p) {
                if (!is_array($p) || !is_string($p['type'] ?? null)) continue;
                $template = is_string($p['template'] ?? null) ? $p['template'] : (string) $page;
                $add($template, is_string($p['list'] ?? null) && $p['list'] !== '' ? "{$p['type']}/{$p['list']}" : $p['type']);
            }
            foreach (is_array($schema['promoted'] ?? null) ? $schema['promoted'] : [] as $p) {
                if (is_array($p)) $promoted["{$p['from']}/{$p['list']}"] = (string) $p['to'];
            }
        }
        foreach ($templates as $html) {
            foreach (self::pageLinks($html) as [$page, $context]) {
                // Un vecchio link a un elenco promosso: il contesto è il tipo nuovo
                $parts = explode('/', $context);
                if (count($parts) >= 2 && isset($promoted["{$parts[0]}/{$parts[1]}"])) {
                    $context = implode('/', array_merge([$promoted["{$parts[0]}/{$parts[1]}"]], array_slice($parts, 2)));
                }
                $add($page, $context);
            }
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
                        $inner = self::hasList($schemas, "{$context}/{$target}") ? "{$context}/{$target}" : $target;
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
    private static function hasList(array $schemas, string $context): bool
    {
        $parts = explode('/', $context);
        foreach ($schemas as $schema) {
            $fields = $schema['types'][$parts[0]]['fields'] ?? null;
            for ($i = 1; is_array($fields) && $i < count($parts); $i++) {
                $def = null;
                foreach ($fields as $field) {
                    if (is_array($field) && ($field['name'] ?? null) === $parts[$i]) $def = $field;
                }
                $fields = is_array($def) && ($def['type'] ?? null) === 'list' ? ($def['of'] ?? []) : null;
            }
            if (is_array($fields)) return true;
        }
        return false;
    }
}
