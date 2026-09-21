<?php
declare(strict_types=1);

namespace Kris\Template;

use DOMDocument;
use DOMElement;
use DOMNode;

class DomHelper {
    public static function loadHtml(string $html): DOMDocument {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Il prologo serve solo a forzare UTF-8 durante il parsing: se resta
        // nel documento finisce nell'output di ogni pagina.
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
            }
        }

        return $dom;
    }

    /**
     * Importa un frammento conservando TUTTI i nodi di primo livello:
     * prendendo solo documentElement, un template con due elementi fratelli
     * perderebbe il secondo senza alcun errore.
     */
    public static function importHtml(DOMDocument $dom, string $html): DOMNode {
        $tempDoc = self::loadHtml($html);
        $fragment = $dom->createDocumentFragment();

        foreach (iterator_to_array($tempDoc->childNodes) as $node) {
            $fragment->appendChild($dom->importNode($node, true));
        }

        return $fragment;
    }

    public static function setContent(DOMElement $element, string $value, DOMDocument $dom): void {
        while ($element->firstChild) {
            $element->removeChild($element->firstChild);
        }

        $tempDoc = self::loadHtml('<div>' . $value . '</div>');
        foreach ($tempDoc->documentElement->childNodes as $child) {
            $imported = $dom->importNode($child, true);
            $element->appendChild($imported);
        }
    }
}