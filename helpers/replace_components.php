<?php

function replace_components($el, $data, $dom, $lang)
{
    $id = $el->getAttribute('k-id');
    if (!isset($data[$id]) || !is_array($data[$id])) {
        return;  // No data for this repeatable section, skip updating
    }

    $featureCards = [];
    foreach ($el->getElementsByTagName('div') as $childDiv) {
        if ($childDiv->getAttribute('class') === 'feature-card') {
            $featureCards[] = $childDiv;
        }
    }

    $items = $data[$id];
    $cardsCount = count($featureCards);
    $itemsCount = count($items);

    // Clone or remove cards to match items count
    if ($cardsCount < $itemsCount) {
        $templateCard = $featureCards[$cardsCount - 1];
        for ($i = $cardsCount; $i < $itemsCount; $i++) {
            $newCard = $templateCard->cloneNode(true);
            $el->firstElementChild->appendChild($newCard);
            $featureCards[] = $newCard;
        }
    } elseif ($cardsCount > $itemsCount) {
        for ($i = $itemsCount; $i < $cardsCount; $i++) {
            $el->firstElementChild->removeChild($featureCards[$i]);
        }
        $featureCards = array_slice($featureCards, 0, $itemsCount);
    }

    // Update each card content
    foreach ($featureCards as $index => $card) {
        if (!isset($items[$index])) continue;
        $itemData = $items[$index];

        // Update first img src
        foreach ($card->getElementsByTagName('img') as $img) {
            if (isset($itemData['image']['src'])) {
                $img->setAttribute('src', $itemData['image']['src']);
            }
            break;
        }

        // Update first anchor href and text
        foreach ($card->getElementsByTagName('a') as $a) {
            if (isset($itemData['title']['action'])) {
                $a->setAttribute('href', $itemData['title']['action']);
            }
            if (isset($itemData['title'][$lang])) {
                while ($a->firstChild) {
                    $a->removeChild($a->firstChild);
                }
                $a->appendChild($dom->createTextNode($itemData['title'][$lang]));
            }
            break;
        }

        // Update first paragraph text
        foreach ($card->getElementsByTagName('p') as $p) {
            $text = $itemData['desc'][$lang] ?? $itemData['desc']['en'] ?? '';
            $p->nodeValue = $text;
            break;
        }
    }
}
