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
        return $dom;
    }

    public static function importHtml(DOMDocument $dom, string $html): DOMNode {
        $tempDoc = self::loadHtml($html);
        return $dom->importNode($tempDoc->documentElement, true);
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