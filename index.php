<?php
$ln = $_GET['ln'] ?? "it";

$template = $_GET['page'] ?? "template";
$templatePath = "templates/" . $template . ".html";

function setElementData($dom, $el, $data, $ln) {
    $id = $el->getAttribute('k-id');

    switch ($el->tagName) {
        case 'img':
            if (!isset($data[$id]["src"])) {
                return;
            }
            $el->setAttribute('src', $data[$id]["src"]); // Update image source
            break;

        default:
            if (!isset($data[$id][$ln])) {
                return;
            }
            while ($el->firstChild) {
                $el->removeChild($el->firstChild);
            }
            $el->appendChild($dom->createTextNode($data[$id][$ln]));
            break;
    }
}

$html = file_get_contents($templatePath);
$data = json_decode(file_get_contents('k_data.json'), true);
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
foreach ($dom->getElementsByTagName('*') as $el) {
    if ($el->hasAttribute('k-edit')) {
        setElementData($dom, $el, $data, $ln);
    }
}
echo $dom->saveHTML();
?>
