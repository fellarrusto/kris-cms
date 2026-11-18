<?php
declare(strict_types=1);

namespace Kris\Site;

use Exception;
use Kris\Entity\Entity;
use Kris\Template\Assets;
use Kris\Template\Seo;
use Kris\Store\Stores;
use Kris\Template\TemplateEngine;
use Throwable;

/**
 * Una pagina del sito resa come la vedono i visitatori: template, contenuti,
 * canonical e hreflang, favicon, versione dei file del tema. La usano
 * l'entry point pubblico e l'agente (HTML finito di una pagina in staging):
 * con una sovrapposizione attiva (Paths::useOverlay) legge lo staging.
 *
 * Non manda intestazioni e non termina la richiesta: restituisce stato e HTML.
 */
final class PageRenderer
{
    /**
     * @param array $query parametri della richiesta (page, key, id, path, ln)
     * @param array $server $_SERVER, per l'indirizzo del sito quando seo.json non lo indica
     * @return array{status: int, html: string, lang: string}
     */
    public static function render(array $query, array $server): array
    {
        [$lang, $langs] = self::language($query);
        $key = is_string($query['key'] ?? null) ? $query['key'] : 'homepage';
        $id = (int) ($query['id'] ?? 0);
        $page = is_string($query['page'] ?? null) ? $query['page'] : 'homepage';
        $rawPath = is_string($query['path'] ?? null) ? $query['path'] : '';
        $path = $rawPath === '' ? [] : array_values(array_filter(explode('/', $rawPath), fn($s) => $s !== ''));

        $pagesDoc = Stores::site(null, true)->read('pages');
        $pages = $pagesDoc->rev === '' ? ['homepage'] : ($pagesDoc->value['allowed_pages'] ?? ['homepage']);
        // La 404 del tema non e una pagina navigabile: si vede solo come 404
        if ($page === '404' || !in_array($page, (array) $pages, true)) {
            return ['status' => 404, 'html' => self::notFound($lang), 'lang' => $lang];
        }
        try {
            $entity = Entity::fromPath('k_data', $key, $id, $path);
        } catch (Exception $e) {
            return ['status' => 404, 'html' => self::notFound($lang), 'lang' => $lang];
        }
        $html = (new TemplateEngine($lang))->render((string) file_get_contents(Paths::template($page)), $entity);

        // Canonical e alternative per lingua, se il template non li ha gia.
        $seo = Seo::config(KRIS_ROOT);
        if ($seo['head']) {
            $html = Seo::inject($html, Seo::headTags(Seo::baseUrl($seo, $server), $page, $key, $id, $path, $lang, $langs, self::routes()));
        }
        $html = self::withMeta($html, $entity, $lang, Seo::baseUrl($seo, $server));
        return ['status' => 200, 'html' => self::finish(self::withHead($html, $entity, $lang), $lang), 'lang' => $lang];
    }

    /** Le rotte degli indirizzi leggibili, o null se il sito non li usa (seo.json, pretty_urls). */
    public static function routes(): ?array
    {
        if (!Seo::config(KRIS_ROOT)['pretty_urls']) return null;
        $store = Stores::site(null, true);
        $pages = $store->read('pages');
        $routes = Router::routes(
            $pages->rev === '' ? ['homepage'] : (array) ($pages->value['allowed_pages'] ?? ['homepage']),
            Seo::config(KRIS_ROOT)['sitemap'],
            $store->read('model')->value
        );
        // Gli slug dei contenuti, per lingua, dal campo indicato nella regola della sitemap
        $langs = self::language([])[1];
        $data = null;
        foreach ($routes as &$route) {
            if (!isset($route['slug'])) continue;
            $data ??= $store->read('data')->value;
            $route['slugs'] = [];
            foreach ($data as $entity) {
                if (($entity['name'] ?? null) !== $route['key'] || Entity::isHidden($entity)) continue;
                $value = null;
                foreach ((array) ($entity['data'] ?? []) as $field) {
                    if (($field['name'] ?? null) === $route['slug']) $value = $field['value'] ?? null;
                }
                foreach ($langs as $lang) {
                    $route['slugs'][(int) $entity['id']][$lang] = Router::slug((string) (is_array($value) ? ($value[$lang] ?? '') : ($value ?? '')));
                }
            }
        }
        unset($route);
        return $routes;
    }

    /**
     * La lingua chiesta, fra quelle attive; altrimenti la principale.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function language(array $query): array
    {
        $langs = ['it', 'en'];
        try {
            $settings = Stores::site()->read('settings')->value;
        } catch (\Kris\Entity\StorageException $e) {
            // Impostazioni illeggibili: le lingue predefinite, la pagina si vede comunque
            $settings = [];
        }
        if (!empty($settings['languages']) && is_array($settings['languages'])) {
            $langs = array_values($settings['languages']);
        }
        $lang = $query['ln'] ?? $langs[0];
        return [in_array($lang, $langs, true) ? (string) $lang : $langs[0], $langs];
    }

    /**
     * La pagina 404. In ordine:
     *  - template/404.html del tema, resa come le altre pagine: i testi dalla
     *    raccolta not_found (contenuto 0) se il tema ne ha una sua, altrimenti
     *    da kris_notfound (la sezione Pagina 404 dell'editor); navbar e footer
     *    se il template li include con k-component;
     *  - 404.php nella root del sito (siti precedenti alla 1.4.3);
     *  - quella generica di Kris, con i testi di kris_notfound.
     */
    public static function notFound(string $lang): string
    {
        $template = Paths::template('404');
        if (is_file($template)) {
            try {
                try {
                    $entity = new Entity('k_data', 'not_found', 0);
                } catch (Exception $e) {
                    try {
                        $entity = new Entity('k_data', 'kris_notfound', 0);
                    } catch (Exception $e) {
                        // Il template puo non avere testi propri: entita vuota
                        $entity = new Entity('k_data', 'not_found', 0, ['name' => 'not_found', 'id' => 0, 'data' => []]);
                    }
                }
                return self::finish(self::withHead((new TemplateEngine($lang))->render((string) file_get_contents($template), $entity), $entity, $lang), $lang);
            } catch (Throwable $e) {
                // Un template rotto non deve trasformare la 404 in un errore: si usa quella di riserva
            }
        }
        $site = KRIS_ROOT . '/404.php';
        $notFound = self::notFoundTexts($lang);
        ob_start();
        require is_file($site) ? $site : KRIS_DIR . '/404.php';
        return self::finish((string) ob_get_clean(), $lang);
    }

    /**
     * La pagina «Lavori in corso» se l'interruttore della sezione (kris_wip) è
     * acceso, altrimenti null: template/kris_wip.html del tema, resa con i campi
     * della sezione, o quella di serie di Kris.
     */
    public static function workInProgress(string $lang): ?string
    {
        try {
            $entity = new Entity('k_data', 'kris_wip', 0);
        } catch (Throwable $e) {
            return null; // sito senza la sezione (migrazione non ancora applicata)
        }
        if ($entity->getData('enabled') !== '1') return null;
        $template = Paths::template('kris_wip');
        if (is_file($template)) {
            try {
                return self::finish(self::withHead((new TemplateEngine($lang))->render((string) file_get_contents($template), $entity), $entity, $lang), $lang);
            } catch (Throwable $e) {
                // Template rotto: la pagina di serie
            }
        }
        $wip = [];
        foreach (['title', 'text'] as $field) {
            $value = $entity->getData($field, $lang);
            if (is_string($value) && trim($value) !== '') $wip[$field] = trim($value);
        }
        ob_start();
        require KRIS_DIR . '/wip.php';
        return self::finish((string) ob_get_clean(), $lang);
    }

    /** I testi della sezione Pagina 404 per la 404 di serie; vuoti se mancano (valgono quelli predefiniti). */
    public static function notFoundTexts(string $lang): array
    {
        try {
            $entity = new Entity('k_data', 'kris_notfound', 0);
        } catch (Throwable $e) {
            return [];
        }
        $texts = [];
        foreach (['title', 'text', 'button_label', 'button_url'] as $field) {
            $value = $entity->getData($field, $lang);
            if (is_string($value) && trim($value) !== '') $texts[$field] = trim($value);
        }
        return $texts;
    }

    /**
     * Titolo, description, Open Graph e verifiche dalle proprietà del sito
     * (kris_site) e dai campi seo_title, seo_description, seo_image della
     * pagina, se il tipo li ha. Ogni tag si aggiunge solo se il template non
     * ce l'ha già; il titolo esistente passa dal modello del titolo.
     */
    private static function withMeta(string $html, Entity $page, string $lang, string $base): string
    {
        $pos = stripos($html, '</head>');
        if ($pos === false) return $html;
        try {
            $site = new Entity('k_data', 'kris_site', 0);
        } catch (Throwable $e) {
            $site = Entity::fromArray(['id' => 0, 'data' => []], 'kris_site');
        }
        $text = fn(Entity $e, string $field): string => is_string($v = $e->getData($field, $lang)) ? trim($v) : '';
        $esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $has = fn(string $pattern): bool => preg_match($pattern, substr($html, 0, $pos)) === 1;
        $absolute = fn(string $url): string => $url === '' || preg_match('#^https?://#', $url) ? $url : $base . '/' . ltrim($url, '/');

        // Titolo: quello SEO della pagina, altrimenti quello del template, passato dal modello
        $siteName = $text($site, 'site_name');
        $pattern = $text($site, 'title_template') ?: '{title}';
        $current = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1 ? html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $base_title = $text($page, 'seo_title') ?: ($current ?: $siteName);
        $title = $base_title === '' ? '' : trim(str_replace(['{title}', '{site}'], [$base_title, $siteName], $pattern), " |-·");
        if ($title !== '' && $title !== $current) {
            $html = $current === '' && !preg_match('#<title[^>]*>#i', $html)
                ? substr($html, 0, $pos) . '<title>' . $esc($title) . "</title>\n" . substr($html, $pos)
                : (string) preg_replace_callback('#<title([^>]*)>.*?</title>#is', fn(array $t): string => '<title' . $t[1] . '>' . $esc($title) . '</title>', $html, 1);
            $pos = stripos($html, '</head>');
        }

        $description = $text($page, 'seo_description') ?: $text($site, 'description');
        $image = $absolute($text($page, 'seo_image') ?: $text($site, 'share_image'));
        $tags = [];
        if ($description !== '' && !$has('#<meta[^>]+name=["\']description["\']#i')) $tags[] = '<meta name="description" content="' . $esc($description) . '">';
        // Open Graph solo se il sito ha qualcosa da dire: senza proprietà configurate le pagine restano com'erano
        if (!$has('#property=["\']og:#i') && $title !== '' && ($siteName !== '' || $description !== '' || $image !== '')) {
            $tags[] = '<meta property="og:type" content="website">';
            $tags[] = '<meta property="og:title" content="' . $esc($title) . '">';
            if ($siteName !== '') $tags[] = '<meta property="og:site_name" content="' . $esc($siteName) . '">';
            if ($description !== '') $tags[] = '<meta property="og:description" content="' . $esc($description) . '">';
            $tags[] = '<meta property="og:locale" content="' . $esc($lang) . '">';
            if ($image !== '') {
                $tags[] = '<meta property="og:image" content="' . $esc($image) . '">';
                $tags[] = '<meta name="twitter:card" content="summary_large_image">';
            }
        }
        foreach (['google_verification' => 'google-site-verification', 'bing_verification' => 'msvalidate.01'] as $field => $name) {
            $code = $text($site, $field);
            if ($code !== '' && !$has('#name=["\']' . preg_quote($name, '#') . '["\']#i')) $tags[] = '<meta name="' . $name . '" content="' . $esc($code) . '">';
        }
        return $tags ? substr($html, 0, $pos) . implode("\n", $tags) . "\n" . substr($html, $pos) : $html;
    }

    /**
     * template/kris_head.html, se il tema ce l'ha: inserito prima di </head> in
     * ogni pagina, reso con il contenuto della pagina. Il posto per meta di
     * verifica, script globali, dati strutturati: una volta sola invece che in
     * ogni template.
     */
    private static function withHead(string $html, Entity $entity, string $lang): string
    {
        $file = Paths::template('kris_head');
        if (!is_file($file)) return $html;
        $head = (new TemplateEngine($lang))->render((string) file_get_contents($file), $entity);
        $pos = stripos($html, '</head>');
        return $pos === false ? $html : substr($html, 0, $pos) . $head . "\n" . substr($html, $pos);
    }

    /** Ultimi ritocchi di ogni pagina, 404 compresa: favicon e versione dei file del tema. */
    public static function finish(string $html, string $lang): string
    {
        return Assets::version(self::withFavicon($html, $lang), KRIS_ROOT);
    }

    /** La favicon scelta nell'editor (raccolta kris_site), se c'e. */
    private static function withFavicon(string $html, string $lang): string
    {
        try {
            $url = (new Entity('k_data', 'kris_site', 0))->getData('favicon', $lang);
        } catch (Throwable $e) {
            // Siti senza la raccolta (migrazione non ancora applicata): niente favicon
            return $html;
        }
        return Seo::injectIcon($html, is_string($url) ? $url : null);
    }
}
