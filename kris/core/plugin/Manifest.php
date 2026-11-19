<?php
declare(strict_types=1);

namespace Kris\Plugin;

/**
 * plugin.json, il manifest di un plugin (docs/2.0/plugin.md, 3): controllato
 * all'installazione e a ogni lettura, restituito con tutte le chiavi.
 */
final class Manifest
{
    public const API = 1;
    public const NAME = '/^[a-z][a-z0-9_]{1,30}$/';
    private const HOOKS = ['head', 'body_end', 'published', 'content_saved'];
    private const CATEGORIES = ['technical', 'thirdparty', 'statistics', 'marketing'];
    private const FIELD_TYPES = ['text', 'richtext', 'media', 'select', 'boolean'];
    private const ID = '/^[a-z][a-z0-9_-]{0,40}$/';

    /** Il manifest di un plugin installato o estratto; null se manca o non è valido. */
    public static function read(string $dir): ?array
    {
        try {
            return self::parse((string) @file_get_contents($dir . '/plugin.json'));
        } catch (PluginException) {
            return null;
        }
    }

    /** @throws PluginException con il motivo */
    public static function parse(string $json): array
    {
        $m = json_decode($json, true);
        if (!is_array($m)) throw new PluginException('Il plugin non ha un plugin.json leggibile.');
        if (($m['api'] ?? null) !== self::API) {
            throw new PluginException('Il plugin è scritto per una versione dei plugin che questa versione di Kris non conosce: aggiorna Kris.');
        }
        $name = $m['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME, $name) !== 1) throw new PluginException('Il nome del plugin non è valido.');
        $version = $m['version'] ?? null;
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) throw new PluginException('La versione del plugin non è valida.');
        $str = fn(mixed $v, int $max): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';

        $requires = is_array($m['requires'] ?? null) ? $m['requires'] : [];
        $types = [];
        foreach (self::listOf($m['types'] ?? []) as $type) {
            if (!is_string($type) || !str_starts_with($type, "plg_{$name}_") || preg_match('/^[a-z][a-z0-9_]*$/', $type) !== 1) {
                throw new PluginException("Il plugin dichiara un tipo fuori dal suo prefisso plg_{$name}_.");
            }
            $types[] = $type;
        }
        $settings = [];
        foreach (self::listOf($m['settings'] ?? []) as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null) || preg_match('/^[a-z][a-z0-9_]*$/', $field['name']) !== 1
                || !in_array($field['type'] ?? null, self::FIELD_TYPES, true)) {
                throw new PluginException('Un campo delle impostazioni del plugin non è valido.');
            }
            $def = ['name' => $field['name'], 'type' => $field['type'], 'localized' => ($field['localized'] ?? false) === true,
                'description' => $str($field['description'] ?? '', 200)];
            if (($s = $str($field['section'] ?? '', 60)) !== '') $def['section'] = $s;
            if ($field['type'] === 'select') {
                $def['options'] = array_values(array_filter(self::listOf($field['options'] ?? []), 'is_string'));
                if (!empty($field['multiple'])) $def['multiple'] = true;
            }
            $settings[] = $def;
        }
        if ($settings) $types = array_values(array_unique([...$types, "plg_{$name}_settings"]));

        $endpoints = [];
        foreach (self::listOf($m['endpoints'] ?? []) as $e) {
            if (!is_array($e) || !is_string($e['name'] ?? null) || preg_match(self::ID, $e['name']) !== 1) {
                throw new PluginException('Un endpoint del plugin non è valido.');
            }
            $methods = array_values(array_intersect(['GET', 'POST'], array_map('strtoupper', array_filter(self::listOf($e['methods'] ?? ['GET']), 'is_string'))));
            $rate = is_string($e['rate'] ?? null) && preg_match('/^(\d{1,4})\/(\d{1,4})([smh])$/', $e['rate']) === 1 ? $e['rate'] : '30/1m';
            $endpoints[$e['name']] = ['name' => $e['name'], 'methods' => $methods ?: ['GET'], 'rate' => $rate, 'form_token' => !empty($e['form_token'])];
        }

        $editor = is_array($m['editor'] ?? null) ? $m['editor'] : [];
        $views = [];
        foreach (self::listOf($editor['views'] ?? []) as $v) {
            if (!is_array($v) || !is_string($v['name'] ?? null) || preg_match(self::ID, $v['name']) !== 1) {
                throw new PluginException('Una schermata del plugin non è valida.');
            }
            $views[] = ['name' => $v['name'], 'title' => $str($v['title'] ?? $v['name'], 40), 'icon' => $str($v['icon'] ?? 'puzzle', 30), 'nav' => ($v['nav'] ?? true) !== false];
        }
        $module = $editor['module'] ?? null;
        if ($views && (!is_string($module) || preg_match('#^[a-z0-9_\-/]+\.js$#', $module) !== 1 || str_contains($module, '..'))) {
            throw new PluginException('Il plugin ha schermate ma non dice dove sta il loro modulo (editor.module, un file .js di public/).');
        }
        $viewNames = array_column($views, 'name');
        $dashboard = is_string($editor['dashboard'] ?? null) && in_array($editor['dashboard'], $viewNames, true) ? $editor['dashboard'] : null;
        $actions = [];
        foreach (self::listOf($editor['actions'] ?? []) as $a) {
            if (!is_string($a) || preg_match(self::ID, $a) !== 1) throw new PluginException('Un\'azione dell\'editor del plugin non è valida.');
            $actions[] = $a;
        }

        $hooks = [];
        foreach (self::listOf($m['hooks'] ?? []) as $hook) {
            $ok = is_string($hook) && (in_array($hook, self::HOOKS, true)
                || preg_match('/^block:[a-z][a-z0-9_-]{0,40}$/', $hook) === 1
                || (str_starts_with($hook, 'endpoint:') && isset($endpoints[substr($hook, 9)]))
                || (str_starts_with($hook, 'editor:') && in_array(substr($hook, 7), $actions, true)));
            if (!$ok) throw new PluginException('Il plugin dichiara un aggancio sconosciuto: ' . $str($hook, 60) . '.');
            $hooks[] = $hook;
        }
        // Endpoint e azioni dichiarati sono agganci anche senza ripeterli in hooks
        foreach (array_keys($endpoints) as $e) $hooks[] = "endpoint:{$e}";
        foreach ($actions as $a) $hooks[] = "editor:{$a}";

        $cookies = [];
        foreach (self::listOf($m['cookies'] ?? []) as $c) {
            if (!is_array($c) || !is_string($c['name'] ?? null) || !in_array($c['category'] ?? null, self::CATEGORIES, true)) {
                throw new PluginException('Un cookie dichiarato dal plugin non è valido (nome e categoria).');
            }
            $cookies[] = ['name' => $str($c['name'], 80), 'duration' => $str($c['duration'] ?? '', 40), 'provider' => $str($c['provider'] ?? '', 120),
                'category' => $c['category'], 'purpose' => $str($c['purpose'] ?? '', 300)];
        }
        $domains = [];
        foreach (self::listOf($m['domains'] ?? []) as $d) {
            if (!is_string($d) || preg_match('/^(?=.{1,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/', strtolower($d)) !== 1) throw new PluginException('Un dominio dichiarato dal plugin non è valido.');
            $domains[] = strtolower($d);
        }

        return [
            'api' => self::API, 'name' => $name, 'version' => $version,
            'title' => $str($m['title'] ?? $name, 60) ?: $name,
            'description' => $str($m['description'] ?? '', 300),
            'requires' => [
                'kris' => is_string($requires['kris'] ?? null) ? $requires['kris'] : '2.0.0',
                'php' => is_string($requires['php'] ?? null) ? $requires['php'] : '8.3',
                'extensions' => array_values(array_filter(self::listOf($requires['extensions'] ?? []), 'is_string')),
            ],
            'types' => $types, 'settings' => $settings, 'hooks' => array_values(array_unique($hooks)),
            'editor' => ['module' => $views ? $module : null, 'style' => is_string($editor['style'] ?? null) && preg_match('#^[a-z0-9_\-/]+\.css$#', $editor['style']) === 1 && !str_contains($editor['style'], '..') ? $editor['style'] : null,
                'views' => $views, 'dashboard' => $dashboard, 'settings_tab' => $settings !== [] && ($editor['settings_tab'] ?? true) !== false, 'actions' => $actions],
            'endpoints' => array_values($endpoints), 'cookies' => $cookies, 'domains' => array_values(array_unique($domains)),
            'database_recommended' => ($m['database_recommended'] ?? false) === true,
        ];
    }

    /** Il requisito che manca su questo hosting, o null. */
    public static function unmet(array $manifest, string $krisVersion): ?string
    {
        $req = $manifest['requires'];
        if (version_compare($krisVersion, $req['kris'], '<')) return "Serve Kris {$req['kris']} o successiva (qui c'è la {$krisVersion}).";
        if (version_compare(PHP_VERSION, $req['php'], '<')) return "Serve PHP {$req['php']} o successivo (qui c'è il " . PHP_VERSION . ').';
        foreach ($req['extensions'] as $ext) {
            if (!extension_loaded($ext)) return "Serve l'estensione PHP {$ext}: chiedi all'hosting di attivarla.";
        }
        return null;
    }

    private static function listOf(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? $v : [];
    }
}
