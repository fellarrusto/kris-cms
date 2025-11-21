<?php
declare(strict_types=1);

require_once 'vendor/autoload.php';

use Kris\Entity\Entity;
use Kris\Template\TemplateEngine;

$key = $_GET['key'] ?? 'homepage';
$index = (int)($_GET['id'] ?? 0);
$template = $_GET['page'] ?? 'homepage';
$lang = $_GET['ln'] ?? 'it';

// Initialize template engine
$engine = new TemplateEngine($lang);

// Load page entity
$entity = new Entity('k_data', $key, $index);

// Get page template
$html = file_get_contents("template/{$template}.html");

// Render and output the page
echo $engine->render($html, $entity);