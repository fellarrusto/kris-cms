<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Kris\Entity\Entity;
use Kris\Site\Paths;
use Kris\Staging\Preview;
use Kris\Staging\Staging;
use Kris\Template\TemplateEngine;
use Kris\Template\Seo;

/** La 404 del sito, se ne ha una propria, altrimenti quella del framework. */
function kris_not_found_page(): string
{
    $site = KRIS_ROOT . '/404.php';
    return is_file($site) ? $site : KRIS_DIR . '/404.php';
}

// Durante un aggiornamento il sito risponde 503. Se l'aggiornamento si e
// interrotto (manutenzione scaduta) il sito torna visibile comunque.
$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    require KRIS_DIR . '/maintenance.php';
    exit;
}

// Anteprima dello staging: solo con una sessione dell'editor. Senza, i
// parametri sono ignorati e si vede il sito come lo vedono tutti.
$preview = null;
if (Preview::requested($_GET) && Preview::isAdmin()) {
    $staging = new Staging();
    if (isset($_GET['kris_preview_asset'])) {
        Preview::serveAsset($staging, (string) $_GET['kris_preview_asset']);
        exit;
    }
    if ($staging->exists()) {
        $preview = $staging;
        Paths::useOverlay($staging->dir());
        Preview::headers();
    }
}

$key = $_GET['key'] ?? 'homepage';
$index = (int)($_GET['id'] ?? 0);
$requestedPage = $_GET['page'] ?? 'homepage';

// La lingua arriva dall'URL: va confrontata con quelle configurate, non
// usata cosi com'e (finisce nei contenuti e negli attributi delle pagine).
$settingsFile = KRIS_ROOT . '/data/cms_settings.json';
$allowedLangs = ['it', 'en'];
if (is_file($settingsFile)) {
    $settings = json_decode((string) file_get_contents($settingsFile), true);
    if (!empty($settings['languages']) && is_array($settings['languages'])) {
        $allowedLangs = array_values($settings['languages']);
    }
}
$lang = $_GET['ln'] ?? $allowedLangs[0];
if (!in_array($lang, $allowedLangs, true)) {
    $lang = $allowedLangs[0];
}
$rawPath = $_GET['path'] ?? '';
$path = $rawPath === '' ? [] : array_values(array_filter(explode('/', $rawPath), fn($s) => $s !== ''));

// Load allowed pages whitelist
$configPath = Paths::file('config/allowed_pages.json');
$allowedPages = json_decode(file_get_contents($configPath), true)['allowed_pages'] ?? ['homepage'];

// Validate requested page against whitelist
if (!in_array($requestedPage, $allowedPages, true)) {
    require_once kris_not_found_page();
    exit;
}

$template = $requestedPage;

// Initialize template engine
$engine = new TemplateEngine($lang);

// Load page entity (resolves nested sub-entities via path)
try {
    $entity = Entity::fromPath('k_data', $key, $index, $path);
} catch (Exception $e) {
    require_once kris_not_found_page();
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
if ($preview !== null) {
    $output = Preview::decorate($output, $preview, 'editor/index.php?action=staging', Preview::exitUrl($_GET));
}
echo $output;