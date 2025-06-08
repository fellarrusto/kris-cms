<?php
declare(strict_types=1);

require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/TemplateManager.php';

use Editor\Includes\TemplateManager;

try {
    // Controllo autenticazione
    checkAuth();

    // Configurazioni
    $paths = include __DIR__ . '/config/paths.php';
    $ln = $_GET['ln'] ?? 'it';
    $template = $_GET['template'] ?? 'template.html';
    $templatePath   = $paths['templates'] . '/' . $template;
    $dataPath        = $paths['data'];
    $componentsPath  = rtrim($paths['templates'], '/') . '/components';

    // Inizializzazione e render
    $tm = new TemplateManager(
        $templatePath,
        $dataPath,
        $componentsPath,
        $paths,
        $ln
    );

    $tm->injectComponents();
    $tm->disableInteractiveElements();
    $tm->processEditableContent();
    $tm->injectOverlay($paths['overlay']);
    $tm->addResources();

    echo $tm->render();

} catch (\Exception $e) {
    die("Errore: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
