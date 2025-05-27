<?php

require_once __DIR__ . '/helpers/replace_components.php';
$ln = $_GET['ln'] ?? "it";

$template = $_GET['page'] ?? "template";
$templatePath = "templates/" . $template . ".html";

function setElementData($dom, $el, $data, $ln)
{
    $id = $el->getAttribute('k-id');

    if ($el->hasAttribute('class') && str_contains($el->getAttribute('class'), 'features-repeatable')) {

        if (!isset($data[$id]) || !is_array($data[$id])) return;

        replace_components($el, $data, $dom, $ln);
        
        return;
    }

    switch ($el->tagName) {
        case 'img':
            if (!isset($data[$id]["src"])) {
                return;
            }
            $el->setAttribute('src', $data[$id]["src"]); // Update image source
            break;

        case 'a':
            if (isset($data[$id]["action"])) {
                $el->setAttribute('href', $data[$id]["action"]);
            }

            if (isset($data[$id][$ln])) {
                while ($el->firstChild) {
                    $el->removeChild($el->firstChild);
                }
                $el->appendChild($dom->createTextNode($data[$id][$ln]));
            }
            break;

        case 'button':
            if (isset($data[$id]["action"])) {
                $url = htmlspecialchars($data[$id]["action"], ENT_QUOTES);
                $el->setAttribute('onclick', "window.location.href='{$url}'");
            }
            if (isset($data[$id][$ln])) {
                while ($el->firstChild) {
                    $el->removeChild($el->firstChild);
                }
                $el->appendChild($dom->createTextNode($data[$id][$ln]));
            }
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
$componentPath = 'templates/components/repeatable-content.html';
$componentHtml = '';
if (file_exists($componentPath)) {
    $componentHtml = file_get_contents($componentPath);
    $html = preg_replace_callback(
        '#<div([^>]*)class="([^"]*features-repeatable[^"]*)"([^>]*)>\s*</div>#i',
        function ($matches) use ($componentHtml) {
            // rebuild the div preserving all attributes
            return "<div{$matches[1]}class=\"{$matches[2]}\"{$matches[3]}>"
                . $componentHtml
                . "</div>";
        },
        $html
    );
}

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
