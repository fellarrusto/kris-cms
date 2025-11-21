<?php
namespace Kris\Template;
use DOMDocument;

class DomHelper {
    public static function loadHtml($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        return $dom;
    }
    
    public static function importHtml($dom, $html) {
        $tempDoc = self::loadHtml($html);
        return $dom->importNode($tempDoc->documentElement, true);
    }
    
    public static function setContent($element, $value, $dom) {
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