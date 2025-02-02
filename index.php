<?php
$ln = $_GET['ln'] ?? "it";

$template = $_GET['page'] ?? "template";
$templatePath = "templates/" . $template . ".html";

$html = file_get_contents($templatePath);
$data = json_decode(file_get_contents('k_data.json'), true);
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
foreach ($dom->getElementsByTagName('editable') as $el) {
    $id = $el->getAttribute('k-id');
    if(isset($data[$id][$ln])) {
        while($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($dom->createTextNode($data[$id][$ln]));
    }
}
echo $dom->saveHTML();
?>
