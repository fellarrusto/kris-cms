<?php

function replaceComponents($el, $data, $dom, $lang)
{
    $id = $el->getAttribute('k-id');
    if (!isset($data[$id]) || !is_array($data[$id])) {
        return;  // No data for this repeatable section, skip updating
    }

    $card = null;
    foreach ($el->getElementsByTagName('div') as $childDiv) {
        if ($childDiv->hasAttribute('k-card')) {
            $card = $childDiv;
            break;
        }
    }

    if (!$card) {
        return;
    }

    $allCardsData = $data[$id];

    foreach ($allCardsData as $cardKey => $cardContent) {
        $newCard = $card->cloneNode(true);
        
        $newCard->setAttribute('k-id', $cardKey);

        foreach ($newCard->getElementsByTagName('*') as $childElement) {
            if ($childElement->hasAttribute('k-id')) {
                $childId = $childElement->getAttribute('k-id');

                if (isset($cardContent[$childId])) {
                    switch ($childElement->tagName) {
                        case 'img':
                            if (isset($cardContent[$childId]['src'])) {
                                $childElement->setAttribute('src', $cardContent[$childId]['src']);
                            }
                            break;

                        case 'video':
                            if (isset($cardContent[$childId]['src'])) {
                                $childElement->setAttribute('src', $cardContent[$childId]['src']);
                            }
                            break;

                        case 'a':
                            if (isset($cardContent[$childId]['action'])) {
                                $childElement->setAttribute('href', $cardContent[$childId]['action']);
                            }
                            if (isset($cardContent[$childId][$lang])) {
                                while ($childElement->firstChild) {
                                    $childElement->removeChild($childElement->firstChild);
                                }
                                $childElement->appendChild($dom->createTextNode($cardContent[$childId][$lang]));
                            }
                            break;

                        case 'p':
                            if (isset($cardContent[$childId][$lang])) {
                                $childElement->nodeValue = $cardContent[$childId][$lang];
                            } else {
                                $childElement->nodeValue = $cardContent[$childId]['en'] ?? '';
                            }
                            break;

                        default:
                            if (isset($cardContent[$childId][$lang])) {
                                $childElement->nodeValue = $cardContent[$childId][$lang];
                            }
                            break;
                    }
                }
            }
        }
        $el->firstElementChild->appendChild($newCard);
    }
    $el->firstElementChild->removeChild($card);
}
