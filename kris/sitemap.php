<?php
declare(strict_types=1);

/**
 * Sitemap XML generata dai contenuti a ogni richiesta: e sempre aggiornata,
 * anche subito dopo un salvataggio dall'editor. Quali pagine elencare lo
 * dice config/seo.json (vedi Kris\Template\Seo).
 */

require_once __DIR__ . '/bootstrap.php';

use Kris\Entity\JsonStore;
use Kris\Site\Paths;
use Kris\Template\Seo;

$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    http_response_code(503);
    header('Retry-After: 120');
    exit;
}

try {
    $store = \Kris\Store\Stores::site(null, true);
    $data = $store->read('data')->value;
    $settings = \Kris\Store\Stores::site()->read('settings')->value;
    $pages = $store->read('pages')->value;
} catch (Throwable) {
    http_response_code(503);
    exit;
}

$langs = !empty($settings['languages']) && is_array($settings['languages']) ? array_values($settings['languages']) : ['it', 'en'];
$allowedPages = is_array($pages['allowed_pages'] ?? null) ? $pages['allowed_pages'] : ['homepage'];
$config = Seo::config(KRIS_ROOT);

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
echo Seo::sitemapXml(
    Seo::sitemapEntries($data, $config['sitemap'], $allowedPages),
    Seo::baseUrl($config, $_SERVER),
    $langs,
    \Kris\Site\PageRenderer::routes()
);
