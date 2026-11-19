<?php
declare(strict_types=1);

namespace Kris\Plugin;

use Kris\Entity\Entity;
use Kris\Site\PageRenderer;
use Kris\Store\Site;
use Kris\Template\Seo;
use Kris\Template\TemplateEngine;

/**
 * Quello che un plugin riceve in plugin.php (docs/2.0/plugin.md, 4.2): l'unica
 * interfaccia garantita fra le versioni di Kris dentro la stessa api.
 */
final class Context
{
    /** @var array<string, callable> */
    private array $hooks = [];
    private ?string $lang = null;

    public function __construct(private array $manifest)
    {
    }

    public function name(): string
    {
        return $this->manifest['name'];
    }

    /** Registra un aggancio dichiarato nel manifest. */
    public function on(string $hook, callable $fn): void
    {
        if (!in_array($hook, $this->manifest['hooks'], true)) {
            throw new PluginException("L'aggancio «{$hook}» non è dichiarato in plugin.json.");
        }
        $this->hooks[$hook] = $fn;
    }

    /** @internal per Plugins */
    public function hook(string $hook): ?callable
    {
        return $this->hooks[$hook] ?? null;
    }

    /** I valori del pannello di impostazioni, per nome, nella lingua della richiesta. */
    public function settings(): array
    {
        return Plugins::settings($this->name(), $this->language());
    }

    /** I contenuti di un tipo del plugin (uno di quelli in types). */
    public function items(string $type): Items
    {
        if (!in_array($type, $this->manifest['types'], true)) {
            throw new PluginException("Il tipo «{$type}» non è fra quelli del plugin.");
        }
        return new Items($type);
    }

    /**
     * Un contenuto del sito in sola lettura, con i valori nella lingua della
     * richiesta; null se non c'è. Non i tipi di Kris né quelli dei plugin.
     */
    public function read(string $type, int $id = 0): ?array
    {
        if (str_starts_with($type, 'kris_') || str_starts_with($type, 'plg_')) {
            throw new PluginException("Il tipo «{$type}» non si legge dai plugin.");
        }
        try {
            $entity = Entity::load($type, $id);
        } catch (\Exception) {
            return null;
        }
        $out = ['id' => $id];
        foreach (array_keys(Site::fields(Site::load()['schema'], $type)) as $field) $out[$field] = $entity->getData($field, $this->language());
        return $out;
    }

    /**
     * Uno script per head o body_end: con una categoria diversa da technical
     * esce bloccato fino al consenso (consent.js), come gli script del tema.
     */
    public function script(string $src, string $category, array $attrs = []): string
    {
        $html = '<script src="' . self::esc($this->asset($src)) . '"';
        foreach ($attrs as $k => $v) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $k) !== 1 || strtolower((string) $k) === 'type' || str_starts_with(strtolower((string) $k), 'on')) continue;
            $html .= $v === true ? " {$k}" : ' ' . $k . '="' . self::esc((string) $v) . '"';
        }
        return $this->consent($html . '></script>', $category);
    }

    /** HTML scritto dal plugin (uno script inline, un pixel): gli script non tecnici escono bloccati. */
    public function tag(string $html, string $category): string
    {
        return $this->consent($html, $category);
    }

    /** Un frammento di templates/ reso dal motore di Kris: valori escapati, condizioni, nessun PHP. */
    public function render(string $template, array $values): string
    {
        if (preg_match('/^[a-z0-9_-]+$/', $template) !== 1) throw new PluginException('Nome di template non valido.');
        $file = Plugins::root() . '/plugins/' . $this->name() . '/templates/' . $template . '.html';
        if (!is_file($file)) throw new PluginException("Il template «{$template}» del plugin non c'è.");
        $fields = [];
        foreach ($values as $k => $v) $fields[(string) $k] = ['name' => (string) $k, 'type' => is_array($v) ? 'list' : 'text', 'localized' => false];
        $values = array_map(fn($v) => is_array($v) ? array_values(array_map(fn($row, $i) => ['id' => $i, 'values' => is_array($row) ? $row : []], $v, array_keys(array_values($v)))) : $v, $values);
        return (new TemplateEngine($this->language()))->render((string) file_get_contents($file), new Entity('plg_' . $this->name(), 0, $values, $fields));
    }

    /** Indirizzo, dalla cartella del sito, di un file pubblico del plugin o di un suo endpoint (endpoint:<nome>). */
    public function url(string $path): string
    {
        if (str_starts_with($path, 'endpoint:')) {
            return 'index.php?kris_plugin=' . rawurlencode($this->name()) . '&endpoint=' . rawurlencode(substr($path, 9));
        }
        return $this->asset($path);
    }

    /** Gettone per un modulo verso un endpoint con form_token: va nel campo kris_form_token. */
    public function formToken(string $endpoint): string
    {
        return Plugins::formToken($this->name(), $endpoint);
    }

    public function language(): string
    {
        return $this->lang ??= PageRenderer::language($_GET)[0];
    }

    /** @internal la lingua della pagina in rendering */
    public function setLanguage(string $lang): void
    {
        $this->lang = $lang;
    }

    public function languages(): array
    {
        return Site::languages();
    }

    /** L'indirizzo del sito, senza la barra finale. */
    public function siteUrl(): string
    {
        return Seo::baseUrl(Seo::config(KRIS_ROOT), $_SERVER);
    }

    /** Una riga nel registro del plugin (scheda del plugin nell'editor). */
    public function log(string $message): void
    {
        Plugins::log($this->name(), $message);
    }

    /** Richieste in uscita: solo https, solo verso i domini dichiarati. */
    public function http(): PluginHttp
    {
        return new PluginHttp($this->manifest['domains']);
    }

    private function asset(string $path): string
    {
        if (preg_match('#^https://#', $path)) {
            $host = strtolower((string) parse_url($path, PHP_URL_HOST));
            if (!in_array($host, $this->manifest['domains'], true)) throw new PluginException("Il dominio {$host} non è dichiarato in plugin.json.");
            return $path;
        }
        $path = ltrim($path, '/');
        $prefix = 'assets/plugins/' . $this->name() . '/';
        if (!str_starts_with($path, $prefix)) $path = $prefix . $path;
        if (str_contains($path, '..')) throw new PluginException('Percorso non valido.');
        return $path;
    }

    private function consent(string $html, string $category): string
    {
        if (!in_array($category, ['technical', 'thirdparty', 'statistics', 'marketing'], true)) {
            throw new PluginException("Categoria di consenso sconosciuta: {$category}.");
        }
        if ($category === 'technical') return $html;
        // Ogni <script> non tecnico diventa text/plain con la sua categoria: lo esegue consent.js dopo il consenso
        return (string) preg_replace_callback('#<script\b([^>]*)>#i', function (array $m) use ($category): string {
            $attrs = (string) preg_replace('#\s(type|data-kris-consent-script)=(["\']).*?\2#i', '', $m[1]);
            return '<script type="text/plain" data-kris-consent-script="' . $category . '"' . $attrs . '>';
        }, $html);
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}
