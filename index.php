<?php
$ln = $_GET['ln'] ?? "it";

$template = $_GET['page'] ?? "template";
$templatePath = "templates/" . $template . ".html";

function setElementData($dom, $el, $data, $ln)
{
    $id = $el->getAttribute('k-id');

    if ($el->hasAttribute('class') && str_contains($el->getAttribute('class'), 'features-repeatable')) {

        if (!isset($data[$id]) || !is_array($data[$id])) return;

        $featureCards = [];
        foreach ($el->getElementsByTagName('div') as $childDiv) {
            if ($childDiv->getAttribute('class') === 'feature-card') {
                $featureCards[] = $childDiv;
            }
        }

        $items = $data[$id];
        // Adjust number of cards to match items count
        $cardsCount = count($featureCards);
        $itemsCount = count($items);

        if ($cardsCount < $itemsCount) {
            // Clone last card
            $templateCard = $featureCards[$cardsCount - 1];

            for ($i = $cardsCount; $i < $itemsCount; $i++) {
                // Clone last existing card deeply
                $newCard = $templateCard->cloneNode(true);
                $el->firstElementChild->appendChild($newCard);
                $featureCards[] = $newCard;
            }
        } elseif ($cardsCount > $itemsCount) {
            // Remove extra cards
            for ($i = $itemsCount; $i < $cardsCount; $i++) {
                $el->firstElementChild->removeChild($featureCards[$i]);
            }
            // Trim the array so it matches $itemsCount
            $featureCards = array_slice($featureCards, 0, $itemsCount);
        }

        foreach ($featureCards as $index => $card) {
            if (!isset($items[$index])) continue; // no data for this card, skip

            $itemData = $items[$index];

            // Update <img> src attribute
            foreach ($card->getElementsByTagName('img') as $img) {
                if (isset($itemData['image']['src'])) {
                    $img->setAttribute('src', $itemData['image']['src']);
                }
                break; // only first img in card
            }

            // Update <h3> text content
            foreach ($card->getElementsByTagName('h3') as $h3) {
                $text = $itemData['title'][$ln] ?? $itemData['title']['en'] ?? '';
                $h3->nodeValue = $text;
                break; // only first h3
            }

            // Update <p> text content
            foreach ($card->getElementsByTagName('p') as $p) {
                $text = $itemData['desc'][$ln] ?? $itemData['desc']['en'] ?? '';
                $p->nodeValue = $text;
                break; // only first p
            }
        }

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
