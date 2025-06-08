<?php
require __DIR__ . '/core/DataResolver.php';
require __DIR__ . '/core/ComponentLoader.php';
require __DIR__ . '/core/Renderer.php';

use Core\DataResolver;
use Core\ComponentLoader;
use Core\Renderer;

$ln   = $_GET['ln'] ?? 'it';
$page = $_GET['page'] ?? 'template';

$renderer = new Renderer(
    new DataResolver(__DIR__ . '/k_data.json'),
    new ComponentLoader(__DIR__ . '/templates/components'),
    $ln
);
echo $renderer->render(__DIR__ . "/templates/{$page}.html", $ln);