<?php
declare(strict_types=1);

namespace Kris\Update;

use Kris\Template\Richtext;

/**
 * Controlli sul sito da fare prima e dopo un aggiornamento: cose che Kris non
 * cambia da solo (il .htaccess, un robots.txt scritto a mano) o contenuti
 * scritti con regole vecchie. Solo avvisi, in parole per il cliente.
 */
final class SiteChecks
{
    /**
     * @param array $site ['schema' => …, 'content' => …] del formato 2
     * @return list<string>
     */
    public static function run(string $root, array $site): array
    {
        $root = rtrim($root, '/\\');
        $pages = array_keys(is_array($site['schema']['pages'] ?? null) ? $site['schema']['pages'] : []);
        return array_merge(
            self::redirectLoops(is_file("{$root}/.htaccess") ? (string) file_get_contents("{$root}/.htaccess") : '', $pages),
            self::robots(is_file("{$root}/robots.txt") ? (string) file_get_contents("{$root}/robots.txt") : null),
            self::strippedRichtext($site),
        );
    }

    /**
     * Regole del .htaccess che rimandano una pagina a index.php?page=…: Kris
     * rimanda ?page=… all'indirizzo leggibile, e insieme fanno un giro infinito.
     *
     * @param list<string> $pages
     * @return list<string>
     */
    public static function redirectLoops(string $htaccess, array $pages): array
    {
        $found = [];
        foreach (preg_split('/\R/', $htaccess) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $redirect = preg_match('/^Redirect(Match)?\b/i', $line) === 1
                || (preg_match('/^RewriteRule\b/i', $line) === 1 && preg_match('/\[[^\]]*\bR(=\d+)?\b[^\]]*\]\s*$/i', $line) === 1);
            if (!$redirect || preg_match('/[?&]page=([A-Za-z0-9_-]+)/', $line, $m) !== 1) continue;
            if (in_array($m[1], $pages, true)) $found[$m[1]] = $line;
        }
        if (!$found) return [];
        return ['Il .htaccess rimanda ' . implode(', ', array_map(fn($p) => "/{$p}", array_keys($found)))
            . ' a index.php?page=…, mentre Kris rimanda quegli indirizzi alle pagine leggibili: insieme fanno un giro infinito e le pagine non si aprono. Togli dal .htaccess queste regole: ' . implode(' · ', $found)];
    }

    /** @return list<string> */
    public static function robots(?string $robots): array
    {
        if ($robots === null) return [];
        if (preg_match('#^\s*Disallow:\s*/editor/?\s*$#mi', $robots) === 1 && stripos($robots, '/kris-admin') === false) {
            return ['Il robots.txt del sito blocca ancora /editor/, ma l\'editor ora è in /kris-admin/. Eliminalo (Kris genera da solo un robots.txt corretto, con la sitemap) o sostituisci /editor/ con /kris-admin/.'];
        }
        return [];
    }

    /**
     * Testi formattati con elementi che il filtro toglie (iframe, script,
     * embed): sul sito non si vedono più.
     *
     * @return list<string>
     */
    public static function strippedRichtext(array $site): array
    {
        $types = is_array($site['schema']['types'] ?? null) ? $site['schema']['types'] : [];
        $hits = [];
        foreach (is_array($site['content'] ?? null) ? $site['content'] : [] as $item) {
            $type = (string) ($item['type'] ?? '');
            $fields = $types[$type]['fields'] ?? null;
            if (!is_array($fields)) continue;
            $where = ($types[$type]['label'] ?? $type) . ' #' . (int) ($item['id'] ?? 0);
            self::scan($fields, is_array($item['values'] ?? null) ? $item['values'] : [], $where, $hits);
        }
        if (!$hits) return [];
        $list = array_slice($hits, 0, 10);
        return ['Alcuni testi formattati contengono elementi che Kris non mostra più (iframe, script, embed di Instagram o TikTok): sul sito quelle parti non si vedono. Spostali nel template o in un campo embed: '
            . implode(' · ', $list) . (count($hits) > 10 ? ' e altri ' . (count($hits) - 10) : '') . '.'];
    }

    private static function scan(array $fields, array $values, string $where, array &$hits): void
    {
        foreach ($fields as $def) {
            $name = (string) ($def['name'] ?? '');
            $value = $values[$name] ?? null;
            if (($def['type'] ?? '') === 'list' && is_array($value)) {
                foreach ($value as $child) {
                    self::scan(is_array($def['of'] ?? null) ? $def['of'] : [], is_array($child['values'] ?? null) ? $child['values'] : [], $where . " › {$name} #" . (int) ($child['id'] ?? 0), $hits);
                }
                continue;
            }
            if (($def['type'] ?? '') !== 'richtext') continue;
            foreach (is_array($value) ? $value : [$value] as $text) {
                if (is_string($text) && Richtext::removes($text) !== []) {
                    $hits[] = "{$where} › {$name}";
                    break;
                }
            }
        }
    }
}
