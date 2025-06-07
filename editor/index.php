<?php

require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/includes/TemplateManager.php';

try {
    // Controllo autenticazione
    checkAuth();

    // Configurazioni
    $paths = include __DIR__ . '/config/paths.php';
    
    // Caricamento dati
    $data = json_decode(file_get_contents($paths['data']), true);
    $ln = $_GET['ln'] ?? 'it';
    
    // Inizializzazione template
    $template = $_GET['template'] ?? 'template.html';
    $templatePath = $paths['templates'] . '/' . $template;
    
    $tm = new TemplateManager($templatePath, $data, $ln, $paths);
    $tm->injectComponents();
    $tm->disableInteractiveElements();
    $tm->processEditableContent();
    $tm->injectOverlay($paths['overlay']);
    $tm->addResources();
    
    echo $tm->render();

} catch (Exception $e) {
    die("Errore: " . htmlspecialchars($e->getMessage()));
}