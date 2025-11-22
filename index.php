<?php
declare(strict_types=1);

require_once 'vendor/autoload.php';

use Kris\Entity\Entity;
use Kris\Template\TemplateEngine;

$key = $_GET['key'] ?? 'homepage';
$index = (int)($_GET['id'] ?? 0);
$requestedPage = $_GET['page'] ?? 'homepage';
$lang = $_GET['ln'] ?? 'it';

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

// Load page entity
$entity = new Entity('k_data', $key, $index);

// Get page template
$html = file_get_contents("template/{$template}.html");

// Render and output the page
echo $engine->render($html, $entity);