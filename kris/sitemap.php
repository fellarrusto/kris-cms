<?php
declare(strict_types=1);

/**
 * Sitemap XML generata dai contenuti a ogni richiesta: e sempre aggiornata,
 * anche subito dopo un salvataggio dall'editor. Quali pagine elencare lo
 * dice config/seo.json (vedi Kris\Template\Seo).
 */

require_once __DIR__ . '/bootstrap.php';

use Kris\Entity\JsonStore;
use Kris\Template\Seo;

$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    http_response_code(503);
    header('Retry-After: 120');
    exit;
}

try {
    $data = (new JsonStore(KRIS_ROOT . '/data/k_data.json'))->read([]);
    $settings = (new JsonStore(KRIS_ROOT . '/data/cms_settings.json'))->read([]);
    $pages = (new JsonStore(KRIS_ROOT . '/config/allowed_pages.json'))->read([]);
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
    $langs
);
