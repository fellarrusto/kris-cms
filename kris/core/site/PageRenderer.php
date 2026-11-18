<?php
declare(strict_types=1);

namespace Kris\Site;

use Exception;
use Kris\Entity\Entity;
use Kris\Template\Assets;
use Kris\Template\Seo;
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

        $pages = json_decode((string) @file_get_contents(Paths::file('config/allowed_pages.json')), true)['allowed_pages'] ?? ['homepage'];
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
            $html = Seo::inject($html, Seo::headTags(Seo::baseUrl($seo, $server), $page, $key, $id, $path, $lang, $langs));
        }
        return ['status' => 200, 'html' => self::finish($html, $lang), 'lang' => $lang];
    }

    /**
     * La lingua chiesta, fra quelle attive; altrimenti la principale.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function language(array $query): array
    {
        $settingsFile = KRIS_ROOT . '/data/cms_settings.json';
        $langs = ['it', 'en'];
        if (is_file($settingsFile)) {
            $settings = json_decode((string) file_get_contents($settingsFile), true);
            if (!empty($settings['languages']) && is_array($settings['languages'])) {
                $langs = array_values($settings['languages']);
            }
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
                return self::finish((new TemplateEngine($lang))->render((string) file_get_contents($template), $entity), $lang);
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
