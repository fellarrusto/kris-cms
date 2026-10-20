<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Kris\Entity\Entity;
use Kris\Site\Paths;
use Kris\Staging\Preview;
use Kris\Staging\Staging;
use Kris\Template\TemplateEngine;
use Kris\Template\Seo;

/**
 * La pagina 404, con lo stato 404. In ordine:
 *  - template/404.html del tema, resa come le altre pagine: i testi dalla
 *    raccolta not_found (contenuto 0) se il tema ne ha una sua, altrimenti
 *    da kris_notfound (la sezione Pagina 404 dell'editor); navbar e footer
 *    se il template li include con k-component;
 *  - 404.php nella root del sito (siti precedenti alla 1.4.3);
 *  - quella generica di Kris, con i testi di kris_notfound.
 */
function kris_not_found(string $lang): string
{
    http_response_code(404);
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
            return kris_finish_page((new TemplateEngine($lang))->render((string) file_get_contents($template), $entity), $lang);
        } catch (Throwable $e) {
            // Un template rotto non deve trasformare la 404 in un errore: si usa quella di riserva
        }
    }
    $site = KRIS_ROOT . '/404.php';
    $notFound = kris_not_found_texts($lang);
    ob_start();
    require is_file($site) ? $site : KRIS_DIR . '/404.php';
    http_response_code(404);
    return kris_finish_page((string) ob_get_clean(), $lang);
}

/** I testi della sezione Pagina 404 per la 404 di serie; vuoti se mancano (valgono quelli predefiniti). */
function kris_not_found_texts(string $lang): array
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
function kris_finish_page(string $html, string $lang): string
{
    return \Kris\Template\Assets::version(kris_with_favicon($html, $lang), KRIS_ROOT);
}

/** La favicon scelta nell'editor (raccolta kris_site), se c'e. */
function kris_with_favicon(string $html, string $lang): string
{
    try {
        $url = (new Entity('k_data', 'kris_site', 0))->getData('favicon', $lang);
    } catch (Throwable $e) {
        // Siti senza la raccolta (migrazione non ancora applicata): niente favicon
        return $html;
    }
    return Seo::injectIcon($html, is_string($url) ? $url : null);
}

/** La lingua chiesta, fra quelle attive; altrimenti la principale. */
function kris_request_language(array $query): array
{
    $settingsFile = KRIS_ROOT . '/data/cms_settings.json';
    $allowedLangs = ['it', 'en'];
    if (is_file($settingsFile)) {
        $settings = json_decode((string) file_get_contents($settingsFile), true);
        if (!empty($settings['languages']) && is_array($settings['languages'])) {
            $allowedLangs = array_values($settings['languages']);
        }
    }
    $lang = $query['ln'] ?? $allowedLangs[0];
    return [in_array($lang, $allowedLangs, true) ? (string) $lang : $allowedLangs[0], $allowedLangs];
}

// Durante un aggiornamento il sito risponde 503. Se l'aggiornamento si e
// interrotto (manutenzione scaduta) il sito torna visibile comunque.
$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    require KRIS_DIR . '/maintenance.php';
    exit;
}

// La lingua arriva dall'URL: va confrontata con quelle configurate, non
// usata cosi com'e (finisce nei contenuti e negli attributi delle pagine).
[$lang, $allowedLangs] = kris_request_language($_GET);

// Anteprima dello staging: solo con una sessione dell'editor. Senza, la
// pagina non esiste: chi riceve il link dell'anteprima vede la 404.
$preview = null;
if (Preview::requested($_GET)) {
    if (!Preview::isAdmin()) {
        echo kris_not_found($lang);
        exit;
    }
    $staging = new Staging();
    if (isset($_GET['kris_preview_asset'])) {
        Preview::serveAsset($staging, (string) $_GET['kris_preview_asset']);
        exit;
    }
    $preview = $staging;
    Preview::headers($staging);
    if ($staging->exists()) {
        // I contenuti del sito possono essere cambiati dall'ultima operazione: l'anteprima riparte da quelli
        $staging->rebuild();
        Paths::useOverlay($staging->dir(), $staging->deletedFiles());
    }
}

$key = $_GET['key'] ?? 'homepage';
$index = (int)($_GET['id'] ?? 0);
$requestedPage = $_GET['page'] ?? 'homepage';

$rawPath = $_GET['path'] ?? '';
$path = $rawPath === '' ? [] : array_values(array_filter(explode('/', $rawPath), fn($s) => $s !== ''));

// Load allowed pages whitelist
$configPath = Paths::file('config/allowed_pages.json');
$allowedPages = json_decode(file_get_contents($configPath), true)['allowed_pages'] ?? ['homepage'];

// Validate requested page against whitelist
// La 404 del tema non e una pagina navigabile: si vede solo come 404
if ($requestedPage === '404' || !in_array($requestedPage, $allowedPages, true)) {
    $notFound = kris_not_found($lang);
    echo $preview !== null ? Preview::decorate($notFound, $preview, 'editor/index.php?action=staging', Preview::exitUrl($_GET)) : $notFound;
    exit;
}

$template = $requestedPage;

// Initialize template engine
$engine = new TemplateEngine($lang);

// Load page entity (resolves nested sub-entities via path)
try {
    $entity = Entity::fromPath('k_data', $key, $index, $path);
} catch (Exception $e) {
    $notFound = kris_not_found($lang);
    echo $preview !== null ? Preview::decorate($notFound, $preview, 'editor/index.php?action=staging', Preview::exitUrl($_GET)) : $notFound;
    exit;
}

// Get page template
$html = file_get_contents(Paths::template($template));

// Render and output the page
$output = $engine->render($html, $entity);

// Canonical e alternative per lingua, se il template non li ha gia.
$seo = Seo::config(KRIS_ROOT);
if ($seo['head']) {
    $base = Seo::baseUrl($seo, $_SERVER);
    $output = Seo::inject($output, Seo::headTags($base, (string) $requestedPage, (string) $key, $index, $path, (string) $lang, $allowedLangs));
}
$output = kris_finish_page($output, (string) $lang);
if ($preview !== null) {
    $output = Preview::decorate($output, $preview, 'editor/index.php?action=staging', Preview::exitUrl($_GET));
}
echo $output;