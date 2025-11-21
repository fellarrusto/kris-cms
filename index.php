<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'core/entity/Entity.php';
require_once 'core/template/TemplateEngine.php';

$key = $_GET['key'] ?? 'homepage';
$index = $_GET['id'] ?? 0;
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