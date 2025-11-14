<?php
// index.php
require_once 'core/entity/Entity.php';
require_once 'core/template/TemplateEngine.php';

$page = $_GET['page'] ?? 'homepage';
$lang = $_GET['ln'] ?? 'it';

$entity = new Entity('k_data', $page);
$html = file_get_contents("template/{$page}.html");

$engine = new TemplateEngine($lang);
echo $engine->render($html, $entity);

// $entity = new Entity('k_data', "list_test", 1);

// echo $entity->getData("test_text", "es");

