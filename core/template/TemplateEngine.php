<?php
// core/template/TemplateEngine.php

class TemplateEngine {
    private $lang;

    public function __construct($lang = 'it') {
        $this->lang = $lang;
    }

    public function render($html, $entity) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);
        
        $this->processArrays($xpath, $dom);
        $this->processComponents($xpath, $dom);
        $this->processElements($xpath, $entity);

        return $dom->saveHTML();
    }

    private function processArrays($xpath, $dom) {
        $arrays = $xpath->query('//*[@k-array]');
        foreach ($arrays as $array) {
            $entityName = $array->getAttribute('k-array');
            $templateName = $array->getAttribute('k-template');
            $template = file_get_contents("template/{$templateName}.html");
            
            $ids = $this->getAllEntityIds('k_data', $entityName);
            
            foreach ($ids as $id) {
                $entity = new Entity('k_data', $entityName, $id);
                $rendered = $this->render($template, $entity);
                
                $fragment = $dom->createDocumentFragment();
                $fragment->appendXML($rendered);
                $array->appendChild($fragment);
            }
        }
    }

    private function processComponents($xpath, $dom) {
        $components = $xpath->query('//*[@k-component]');
        foreach ($components as $component) {
            $entityName = $component->getAttribute('k-component');
            $templateName = $component->getAttribute('k-template');
            $index = $component->getAttribute('k-index') ?? 0;
            
            $entity = new Entity('k_data', $entityName, $index);
            $template = file_get_contents("template/{$templateName}.html");
            
            $rendered = $this->render($template, $entity);
            
            $fragment = $dom->createDocumentFragment();
            $fragment->appendXML($rendered);
            $component->parentNode->replaceChild($fragment, $component);
        }
    }

    private function processElements($xpath, $entity) {
        $elements = $xpath->query('//*[@k-id]');
        foreach ($elements as $element) {
            $kid = $element->getAttribute('k-id');
            $dataName = str_replace($entity->get('name') . '.', '', $kid);
            
            $data = $entity->getData($dataName);
            
            if (is_array($data)) {
                $element->nodeValue = $data[$this->lang] ?? $data["en"];
            } else {
                if ($element->tagName === 'a') {
                    $element->setAttribute('href', $data);
                }
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