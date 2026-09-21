<?php
declare(strict_types=1);

require_once 'vendor/autoload.php';

use Kris\Entity\Entity;
use Kris\Template\TemplateEngine;

$key = $_GET['key'] ?? 'homepage';
$index = (int)($_GET['id'] ?? 0);
$requestedPage = $_GET['page'] ?? 'homepage';

// La lingua arriva dall'URL: va confrontata con quelle configurate, non
// usata cosi com'e (finisce nei contenuti e negli attributi delle pagine).
$settingsFile = __DIR__ . '/data/cms_settings.json';
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
$configPath = __DIR__ . '/config/allowed_pages.json';
$allowedPages = json_decode(file_get_contents($configPath), true)['allowed_pages'] ?? ['homepage'];

// Validate requested page against whitelist
if (!in_array($requestedPage, $allowedPages, true)) {
    require_once __DIR__ . '/404.php';
    exit;
}

$template = $requestedPage;

// Initialize template engine
$engine = new TemplateEngine($lang);

// Load page entity (resolves nested sub-entities via path)
try {
    $entity = Entity::fromPath('k_data', $key, $index, $path);
} catch (Exception $e) {
    require_once __DIR__ . '/404.php';
    exit;
}

// Get page template
$html = file_get_contents("template/{$template}.html");

// Render and output the page
echo $engine->render($html, $entity);