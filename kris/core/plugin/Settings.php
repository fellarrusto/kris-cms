<?php
declare(strict_types=1);

namespace Kris\Plugin;

/**
 * Le impostazioni di un plugin: i campi dichiarati nel manifest ("settings"),
 * salvati nel contenuto 0 del tipo plg_<nome>_settings. Le scrivono l'editor
 * (Impostazioni › Plugin) e l'agente (operazione set_plugin_settings).
 */
final class Settings
{
    public static function type(string $plugin): string
    {
        return "plg_{$plugin}_settings";
    }

    /**
     * I valori ripuliti secondo i campi del manifest; i campi non dichiarati
     * restano fuori.
     *
     * @param list<array> $fields i campi "settings" del manifest
     * @param list<string> $languages le lingue del sito
     */
    public static function clean(array $fields, array $values, array $languages): array
    {
        $one = function (array $f, mixed $v): mixed {
            return match ($f['type']) {
                'boolean' => $v === true || $v === 1 || $v === '1',
                'richtext' => \Kris\Template\Richtext::clean(is_string($v) ? $v : ''),
                'select' => \Kris\Entity\Entity::normalizeChoice($f, $v),
                default => is_scalar($v) ? mb_substr(trim((string) $v), 0, 5000) : '',
            };
        };
        $clean = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f['name'], $values)) continue;
            $v = $values[$f['name']];
            if ($f['localized']) {
                $clean[$f['name']] = [];
                foreach ($languages as $lang) $clean[$f['name']][$lang] = $one($f, is_array($v) ? ($v[$lang] ?? null) : ($lang === $languages[0] ? $v : null));
            } else {
                $clean[$f['name']] = $one($f, $v);
            }
        }
        return $clean;
    }

    /** I valori attuali delle impostazioni di un plugin in un elenco di contenuti. */
    public static function values(array $content, string $plugin): array
    {
        foreach ($content as $item) {
            if (($item['type'] ?? null) === self::type($plugin) && (int) ($item['id'] ?? -1) === 0) return is_array($item['values'] ?? null) ? $item['values'] : [];
        }
        return [];
    }

    /** Il contenuto con i valori nuovi uniti a quelli che c'erano. */
    public static function merge(array $content, string $plugin, array $clean): array
    {
        foreach ($content as $i => $item) {
            if (($item['type'] ?? null) === self::type($plugin) && (int) ($item['id'] ?? -1) === 0) {
                $content[$i]['values'] = $clean + (is_array($item['values'] ?? null) ? $item['values'] : []);
                return $content;
            }
        }
        $content[] = ['type' => self::type($plugin), 'id' => 0, 'values' => $clean];
        return $content;
    }
}
