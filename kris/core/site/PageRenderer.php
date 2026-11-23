<?php
declare(strict_types=1);

namespace Kris\Site;

use Exception;
use Kris\Entity\Entity;
use Kris\Template\Assets;
use Kris\Template\Seo;
use Kris\Store\Site;
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
        $schema = Site::load(null, true)['schema'];
        // Senza key e id la pagina rende il suo tipo e il suo contenuto fisso
        $query = Router::fill(Router::routes($schema), $query);
        $key = is_string($query['key'] ?? null) ? $query['key'] : 'homepage';
        $id = (int) ($query['id'] ?? 0);
        $page = is_string($query['page'] ?? null) ? $query['page'] : 'homepage';
        $rawPath = is_string($query['path'] ?? null) ? $query['path'] : '';
        $path = $rawPath === '' ? [] : array_values(array_filter(explode('/', $rawPath), fn($s) => $s !== ''));

        $entry = $schema['pages'][$page] ?? null;
        // La 404 del tema non e una pagina navigabile: si vede solo come 404
        if ($page === '404' || !is_array($entry)) {
            return ['status' => 404, 'html' => self::notFound($lang), 'lang' => $lang];
        }
        // Un vecchio link a un elemento di un elenco promosso a tipo: il contenuto del tipo
        [$key, $id, $path] = Site::normalize($schema, $key, $id, $path);
        try {
            $entity = Entity::fromPath($key, $id, $path);
        } catch (Exception $e) {
            return ['status' => 404, 'html' => self::notFound($lang), 'lang' => $lang];
        }
        $template = is_string($entry['template'] ?? null) ? $entry['template'] : $page;
        $html = (new TemplateEngine($lang))->render((string) file_get_contents(Paths::template($template)), $entity);

        // Canonical e alternative per lingua, se il template non li ha gia.
        $seo = Seo::config(KRIS_ROOT);
        if ($seo['head']) {
            $html = Seo::inject($html, Seo::headTags(Seo::baseUrl($seo, $server), $page, $key, $id, $path, $lang, $langs, self::routes()));
        }
        $html = self::withMeta($html, $entity, $lang, Seo::baseUrl($seo, $server));
        $info = ['page' => $page, 'type' => $key, 'id' => $id, 'path' => $path, 'lang' => $lang, 'status' => 200];
        return ['status' => 200, 'html' => self::finish(self::withHead($html, $entity, $lang), $lang, $info), 'lang' => $lang];
    }

    /** Le rotte degli indirizzi leggibili, o null se il sito non li usa (seo.json, pretty_urls). */
    public static function routes(): ?array
    {
        if (!Seo::config(KRIS_ROOT)['pretty_urls']) return null;
        $site = Site::load(null, true);
        return self::routesFor($site['schema'], $site['content'], self::language([])[1]);
    }

    /** Le rotte di uno schema e dei suoi contenuti, con gli slug per lingua (anche per lo stato dello staging). */
    public static function routesFor(array $schema, array $content, array $langs): array
    {
        $routes = Router::routes($schema);
        // Gli slug dei contenuti, per lingua, dal campo indicato nella pagina
        foreach ($routes as &$route) {
            if (!isset($route['slug'])) continue;
            $route['slugs'] = [];
            foreach ($content as $item) {
                if (($item['type'] ?? null) !== $route['key'] || Entity::isHidden($item)) continue;
                $value = $item['values'][$route['slug']] ?? null;
                foreach ($langs as $lang) {
                    $route['slugs'][(int) $item['id']][$lang] = Router::slug((string) (is_array($value) ? ($value[$lang] ?? '') : ($value ?? '')));
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
        try {
            $langs = Site::languages();
        } catch (\Kris\Entity\StorageException $e) {
            // Archivio illeggibile: le lingue predefinite, la pagina si vede comunque
            $langs = ['it', 'en'];
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
                    $entity = Entity::load('not_found');
                } catch (Exception $e) {
                    try {
                        $entity = Entity::load('kris_notfound');
                    } catch (Exception $e) {
                        // Il template puo non avere testi propri: entita vuota
                        $entity = new Entity('not_found', 0);
                    }
                }
                return self::finish(self::withHead((new TemplateEngine($lang))->render((string) file_get_contents($template), $entity), $entity, $lang), $lang, ['page' => '404', 'lang' => $lang, 'status' => 404]);
            } catch (Throwable $e) {
                // Un template rotto non deve trasformare la 404 in un errore: si usa quella di riserva
            }
        }
        $site = KRIS_ROOT . '/404.php';
        $notFound = self::notFoundTexts($lang);
        ob_start();
        require is_file($site) ? $site : KRIS_DIR . '/404.php';
        return self::finish((string) ob_get_clean(), $lang, ['page' => '404', 'lang' => $lang, 'status' => 404]);
    }

    /**
     * La pagina «Lavori in corso» se l'interruttore della sezione (kris_wip) è
     * acceso, altrimenti null: template/kris_wip.html del tema, resa con i campi
     * della sezione, o quella di serie di Kris.
     */
    public static function workInProgress(string $lang, bool $force = false): ?string
    {
        try {
            $entity = Entity::load('kris_wip');
        } catch (Throwable $e) {
            return null; // sito senza la sezione (migrazione non ancora applicata)
        }
        // $force: l'anteprima dall'editor, anche con l'interruttore spento
        if ($entity->getData('enabled') !== '1' && !$force) return null;
        $template = Paths::template('kris_wip');
        if (is_file($template)) {
            try {
                return self::finish(self::withHead((new TemplateEngine($lang))->render((string) file_get_contents($template), $entity), $entity, $lang), $lang, ['page' => 'kris_wip', 'lang' => $lang, 'status' => 503]);
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
        return self::finish((string) ob_get_clean(), $lang, ['page' => 'kris_wip', 'lang' => $lang, 'status' => 503]);
    }

    /** I testi della sezione Pagina 404 per la 404 di serie; vuoti se mancano (valgono quelli predefiniti). */
    public static function notFoundTexts(string $lang): array
    {
        try {
            $entity = Entity::load('kris_notfound');
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
            $site = Entity::load('kris_site');
        } catch (Throwable $e) {
            $site = new Entity('kris_site', 0);
        }
        $text = fn(Entity $e, string $field): string => is_string($v = $e->getData($field, $lang)) ? trim($v) : '';
        $esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        // I commenti HTML non contano: un <title> o un og commentato non c'è (stesse posizioni, commenti a spazi)
        $mask = fn(string $h): string => (string) preg_replace_callback('/<!--.*?(-->|$)/s', fn(array $c): string => str_repeat(' ', strlen($c[0])), $h);
        $masked = $mask($html);
        $has = fn(string $pattern): bool => preg_match($pattern, substr($masked, 0, $pos)) === 1;
        $absolute = fn(string $url): string => $url === '' || preg_match('#^https?://#', $url) ? $url : $base . '/' . ltrim($url, '/');

        // Titolo: quello SEO della pagina, altrimenti quello del template, passato dal modello
        $siteName = $text($site, 'site_name');
        $pattern = $text($site, 'title_template') ?: '{title}';
        $found = preg_match('#<title([^>]*)>(.*?)</title>#is', $masked, $m, PREG_OFFSET_CAPTURE) === 1;
        $current = $found ? html_entity_decode(trim(strip_tags($m[2][0])), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $base_title = $text($page, 'seo_title') ?: ($current ?: $siteName);
        $title = $base_title === '' ? '' : trim(str_replace(['{title}', '{site}'], [$base_title, $siteName], $pattern), " |-·");
        if ($title !== '' && $title !== $current) {
            $html = $found
                ? substr($html, 0, $m[0][1]) . '<title' . $m[1][0] . '>' . $esc($title) . '</title>' . substr($html, $m[0][1] + strlen($m[0][0]))
                : substr($html, 0, $pos) . '<title>' . $esc($title) . "</title>\n" . substr($html, $pos);
            $pos = stripos($html, '</head>');
            $masked = $mask($html);
        }

        $description = $text($page, 'seo_description') ?: $text($site, 'description');
        $image = $absolute($text($page, 'seo_image') ?: $text($site, 'share_image'));
        $tags = [];
        if ($description !== '' && !$has('#<meta[^>]+name=["\']description["\']#i')) $tags[] = '<meta name="description" content="' . $esc($description) . '">';
        // Open Graph solo se il sito ha qualcosa da dire: senza proprietà configurate le pagine restano com'erano.
        // Tag per tag: il template scrive quelli suoi (og:image della copertina), Kris aggiunge gli altri.
        if ($title !== '' && ($siteName !== '' || $description !== '' || $image !== '')) {
            $og = [
                'og:type' => 'website',
                'og:title' => $title,
                'og:site_name' => $siteName,
                'og:description' => $description,
                'og:locale' => self::ogLocale($lang),
                'og:image' => $image,
            ];
            foreach ($og as $property => $value) {
                if ($value !== '' && !$has('#property=["\']' . preg_quote($property, '#') . '["\']#i')) $tags[] = '<meta property="' . $property . '" content="' . $esc($value) . '">';
            }
            if (($image !== '' || $has('#property=["\']og:image["\']#i')) && !$has('#name=["\']twitter:card["\']#i')) $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        }
        foreach (['google_verification' => 'google-site-verification', 'bing_verification' => 'msvalidate.01'] as $field => $name) {
            $code = $text($site, $field);
            if ($code !== '' && !$has('#name=["\']' . preg_quote($name, '#') . '["\']#i')) $tags[] = '<meta name="' . $name . '" content="' . $esc($code) . '">';
        }
        return $tags ? substr($html, 0, $pos) . implode("\n", $tags) . "\n" . substr($html, $pos) : $html;
    }

    /** La lingua nel formato di Open Graph: it → it_IT, en → en_US, pt-BR → pt_BR. */
    public static function ogLocale(string $lang): string
    {
        if (preg_match('/^([a-z]{2,3})[-_]([a-z]{2})$/i', $lang, $m) === 1) return strtolower($m[1]) . '_' . strtoupper($m[2]);
        $regions = ['en' => 'US', 'el' => 'GR', 'da' => 'DK', 'sv' => 'SE', 'cs' => 'CZ', 'ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'uk' => 'UA', 'ca' => 'ES', 'sl' => 'SI', 'et' => 'EE', 'he' => 'IL', 'ar' => 'AR', 'hi' => 'IN', 'vi' => 'VN', 'nb' => 'NO', 'sq' => 'AL', 'sr' => 'RS'];
        $lang = strtolower($lang);
        return preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang . '_' . ($regions[$lang] ?? strtoupper($lang)) : $lang;
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

    /**
     * Ultimi ritocchi di ogni pagina, 404 compresa: favicon, agganci dei plugin
     * (blocchi, head, body_end), versione dei file del tema e dei plugin.
     */
    public static function finish(string $html, string $lang, array $page = []): string
    {
        $html = \Kris\Plugin\Plugins::decorate(self::withFavicon($html, $lang), $page + ['lang' => $lang, 'status' => 200]);
        return \Kris\Entity\MediaStore::srcset(Assets::version($html, KRIS_ROOT), KRIS_ROOT);
    }

    /** La favicon scelta nell'editor (raccolta kris_site), se c'e. */
    private static function withFavicon(string $html, string $lang): string
    {
        try {
            $url = Entity::load('kris_site')->getData('favicon', $lang);
        } catch (Throwable $e) {
            // Siti senza la raccolta (migrazione non ancora applicata): niente favicon
            return $html;
        }
        return Seo::injectIcon($html, is_string($url) ? $url : null);
    }
}
