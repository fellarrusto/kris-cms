<?php
declare(strict_types=1);

/**
 * Sitemap XML generata dai contenuti a ogni richiesta: e sempre aggiornata,
 * anche subito dopo un salvataggio dall'editor. Le pagine elencate sono
 * quelle dello schema con "sitemap": true (vedi Kris\Template\Seo).
 */

require_once __DIR__ . '/bootstrap.php';

use Kris\Template\Seo;

$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    http_response_code(503);
    header('Retry-After: 120');
    exit;
}

// robots.txt generato (il .htaccess di Kris manda qui /robots.txt se il file non esiste):
// editor escluso, sitemap assoluta, righe aggiuntive delle impostazioni (settings.seo.robots)
if (isset($_GET['robots'])) {
    $config = Seo::config(KRIS_ROOT);
    $raw = $config['robots'];
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /editor/\nDisallow: /kris-admin/\n"
        . (is_string($raw) && trim($raw) !== '' ? trim(str_replace("\r", '', $raw)) . "\n" : '')
        . 'Sitemap: ' . Seo::baseUrl($config, $_SERVER) . "/sitemap.php\n";
    exit;
}

// Sito in lavorazione: anche la sitemap aspetta
if (\Kris\Site\PageRenderer::workInProgress('it') !== null) {
    http_response_code(503);
    header('Retry-After: 3600');
    exit;
}

try {
    $site = \Kris\Store\Site::load(null, true);
    $langs = \Kris\Store\Site::languages();
} catch (Throwable) {
    http_response_code(503);
    exit;
}

$config = Seo::config(KRIS_ROOT);

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
echo Seo::sitemapXml(
    Seo::sitemapEntries($site['schema'], $site['content']),
    Seo::baseUrl($config, $_SERVER),
    $langs,
    \Kris\Site\PageRenderer::routes()
);
