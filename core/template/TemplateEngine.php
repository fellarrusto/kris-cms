<?php
// core/template/TemplateEngine.php

class TemplateEngine {
    private $lang;

    public function __construct($lang = 'it') {
        $this->lang = $lang;
    }

    public function render($html, $entity) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $this->processArrays($dom);
        $this->processComponents($dom);
        $this->processElements($dom, $entity);

        return $dom->saveHTML();
    }

    private function processArrays($dom) {
        $xpath = new DOMXPath($dom);
        $arrays = iterator_to_array($xpath->query('//*[@k-array]'));
        
        foreach ($arrays as $array) {
            $entityName = $array->getAttribute('k-array');
            $templateName = $array->getAttribute('k-template');
            $template = file_get_contents("template/{$templateName}.html");
            
            $ids = $this->getAllEntityIds('k_data', $entityName);
            
            foreach ($ids as $id) {
                $itemEntity = new Entity('k_data', $entityName, $id);
                $rendered = $this->render($template, $itemEntity);
                
                $tempDoc = new DOMDocument();
                @$tempDoc->loadHTML('<?xml encoding="UTF-8">' . $rendered, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                $imported = $dom->importNode($tempDoc->documentElement, true);
                $array->appendChild($imported);
            }
            
            $array->removeAttribute('k-array');
            $array->removeAttribute('k-template');
        }
    }

    private function processComponents($dom) {
        $xpath = new DOMXPath($dom);
        $components = iterator_to_array($xpath->query('//*[@k-component]'));
        
        foreach ($components as $component) {
            if (!$component->parentNode) continue;
            
            $entityName = $component->getAttribute('k-component');
            $templateName = $component->getAttribute('k-template');
            $index = $component->getAttribute('k-index') ?? 0;
            
            $itemEntity = new Entity('k_data', $entityName, $index);
            $template = file_get_contents("template/{$templateName}.html");
            
            $rendered = $this->render($template, $itemEntity);
            
            $tempDoc = new DOMDocument();
            @$tempDoc->loadHTML('<?xml encoding="UTF-8">' . $rendered, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $imported = $dom->importNode($tempDoc->documentElement, true);
            $component->parentNode->replaceChild($imported, $component);
        }
    }

    private function processElements($dom, $entity) {
        $xpath = new DOMXPath($dom);
        $elements = iterator_to_array($xpath->query('//*[@k-id]'));
        
        foreach ($elements as $element) {
            try {
                if (!$element->parentNode) continue;
                
                $dataName = $element->getAttribute('k-id');
                $data = $entity->getData($dataName);
                
                if (is_array($data)) {
                    $value = $data[$this->lang] ?? $data["en"];
                } else {
                    $value = $data ?? '';
                }
                
                if ($element->tagName === 'a') {
                    $element->setAttribute('href', $value);
                } elseif ($element->tagName === 'img') {
                    $element->setAttribute('src', $value);
                } elseif ($element->tagName === 'input' || $element->tagName === 'textarea') {
                    $element->setAttribute('placeholder', $value);
                } else {
                    $tempDoc = new DOMDocument();
                    @$tempDoc->loadHTML('<?xml encoding="UTF-8"><div>' . $value . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    
                    while ($element->firstChild) {
                        $element->removeChild($element->firstChild);
                    }
                    
                    foreach ($tempDoc->documentElement->childNodes as $child) {
                        $imported = $dom->importNode($child, true);
                        $element->appendChild($imported);
                    }
                }
                
                $element->removeAttribute('k-id');
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    private function getAllEntityIds($file, $name) {
        $json = json_decode(file_get_contents("data/{$file}.json"), true);
        $ids = [];
        foreach ($json as $item) {
            if ($item['name'] === $name) {
                $ids[] = $item['id'];
            }
        }
        return $ids;
    }
}