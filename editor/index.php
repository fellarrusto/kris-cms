


<?php

session_start();
if (!isset($_SESSION['logged']) || $_SESSION['logged'] !== true) {
    header("Location: signin.php");
    exit();
}

$ln = $_GET['ln'] ?? "it";
$data = json_decode(file_get_contents('../k_data.json'), true);

$template = $_GET['template'] ?? "template.html";
$templatePath = "../templates/" . $template;

if (!file_exists($templatePath)) {
    die("Template non trovato: " . htmlspecialchars($templatePath));
}

$html = file_get_contents($templatePath);
if ($html === false) {
    die("Errore nel caricamento del template");
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

// Disabilita bottoni e link
foreach ($dom->getElementsByTagName('button') as $btn) {
    $btn->setAttribute('disabled', 'disabled');
}

foreach ($dom->getElementsByTagName('a') as $link) {
    $link->removeAttribute('href');
    $link->setAttribute('style', 'pointer-events: none; color: gray;'); // Opzionale per disattivare visivamente
}

// Load text from file
foreach ($dom->getElementsByTagName('editable') as $el) {
    $id = $el->getAttribute('k-id');
    if(isset($data[$id][$ln])) {
        while($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($dom->createTextNode($data[$id][$ln]));
    }
}

// Aggiungi attributi agli elementi editables
foreach ($dom->getElementsByTagName('editable') as $el) {
    $el->setAttribute('onclick', 'edit(event, this)');
}

// Carica template overlay con percorso assoluto
$overlayPath = __DIR__ . '/overlay.html'; // Usa __DIR__ per percorso assoluto
if (!file_exists($overlayPath)) {
    die("Overlay template non trovato in: " . htmlspecialchars($overlayPath));
}

$overlayTemplate = file_get_contents($overlayPath);
if ($overlayTemplate === false) {
    die("Errore nel caricamento dell'overlay");
}

// Fix per il fragment
$dom->formatOutput = true; // Abilita formattazione output
$fragment = $dom->createDocumentFragment();

// Aggiungi wrapper per validità XML
$wrapperTemplate = '<div>' . $overlayTemplate . '</div>';
if (!$fragment->appendXML($wrapperTemplate)) {
    die("Errore nel parsing dell'overlay. Controlla la sintassi HTML.");
}

// Aggiungi overlay al body
$body = $dom->getElementsByTagName('body')->item(0);
if ($body) {
    $body->appendChild($fragment);
    
    // Aggiungi risorse esterne con percorso assoluto
    $head = $dom->getElementsByTagName('head')->item(0);

    // Aggiungi il JSON come variabile globale JavaScript
    $script = $dom->createElement('script');
    $script->setAttribute('type', 'text/javascript');
    $scriptContent = $dom->createTextNode('window.kData = ' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';');
    $script->appendChild($scriptContent);
    $head->appendChild($script);
    
    // CSS
    $css = $dom->createElement('link');
    $css->setAttribute('rel', 'stylesheet');
    $css->setAttribute('href', '/editor/editor.css'); // Percorso assoluto
    $head->appendChild($css);
    
    // JS
    $js = $dom->createElement('script');
    $js->setAttribute('src', '/editor/editor.js'); // Percorso assoluto
    $body->appendChild($js);
} else {
    die("Nessun tag body trovato nel template");
}

libxml_clear_errors();

echo $dom->saveHTML();

// // Verifica esistenza template
// $template = $_GET['template'] ?? "template.html";
// $templatePath = "../templates/" . $template;

// if (!file_exists($templatePath)) {
//     die("Template non trovato: " . htmlspecialchars($templatePath));
// }

// $html = file_get_contents($templatePath);
// if ($html === false) {
//     die("Errore nel caricamento del template");
// }

// libxml_use_internal_errors(true);
// $dom = new DOMDocument();
// $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

// // Aggiungi attributi agli elementi editables
// foreach ($dom->getElementsByTagName('editable') as $el) {
//     $el->setAttribute('onclick', 'edit(event, this)');
//     $el->setAttribute('data-content', $el->textContent);
// }

// // Carica template overlay con percorso assoluto
// $overlayPath = __DIR__ . '/overlay.html'; // Usa __DIR__ per percorso assoluto
// if (!file_exists($overlayPath)) {
//     die("Overlay template non trovato in: " . htmlspecialchars($overlayPath));
// }

// $overlayTemplate = file_get_contents($overlayPath);
// if ($overlayTemplate === false) {
//     die("Errore nel caricamento dell'overlay");
// }

// // Fix per il fragment
// $dom->formatOutput = true; // Abilita formattazione output
// $fragment = $dom->createDocumentFragment();

// // Aggiungi wrapper per validità XML
// $wrapperTemplate = '<div>' . $overlayTemplate . '</div>';
// if (!$fragment->appendXML($wrapperTemplate)) {
//     die("Errore nel parsing dell'overlay. Controlla la sintassi HTML.");
// }

// // Aggiungi overlay al body
// $body = $dom->getElementsByTagName('body')->item(0);
// if ($body) {
//     $body->appendChild($fragment);
    
//     // Aggiungi risorse esterne con percorso assoluto
//     $head = $dom->getElementsByTagName('head')->item(0);
    
//     // CSS
//     $css = $dom->createElement('link');
//     $css->setAttribute('rel', 'stylesheet');
//     $css->setAttribute('href', '/editor/editor.css'); // Percorso assoluto
//     $head->appendChild($css);
    
//     // JS
//     $js = $dom->createElement('script');
//     $js->setAttribute('src', '/editor/editor.js'); // Percorso assoluto
//     $body->appendChild($js);
// } else {
//     die("Nessun tag body trovato nel template");
// }

// // Debug: mostra errori di parsing
// foreach (libxml_get_errors() as $error) {
//     error_log("Errore XML: " . $error->message);
// }
// libxml_clear_errors();

// echo $dom->saveHTML();
?>