<?php

class ArrayProcessor {
    private $lang;
    private $repo;
    
    public function __construct($lang) {
        $this->lang = $lang;
        $this->repo = new JsonRepository();
    }
    
    public function process($dom) {
        $xpath = new DOMXPath($dom);
        $arrays = iterator_to_array($xpath->query('//*[@k-array]'));
        
        foreach ($arrays as $array) {
            $entityName = $array->getAttribute('k-array');
            $templateName = $array->getAttribute('k-template');
            $template = file_get_contents("template/{$templateName}.html");
            
            $entities = $this->repo->findAll('k_data', $entityName);
            
            foreach ($entities as $entityData) {
                $entity = new Entity('k_data', $entityName, $entityData['id']);
                $engine = new TemplateEngine($this->lang);
                $rendered = $engine->render($template, $entity);
                
                $imported = DomHelper::importHtml($dom, $rendered);
                $array->appendChild($imported);
            }
            
            $array->removeAttribute('k-array');
            $array->removeAttribute('k-template');
        }
    }
}