<?php
namespace Kris\Template;
use Kris\Entity\Entity;
use DOMXPath;

class ComponentProcessor {
    private $lang;
    
    public function __construct($lang) {
        $this->lang = $lang;
    }
    
    public function process($dom) {
        $xpath = new DOMXPath($dom);
        $components = iterator_to_array($xpath->query('//*[@k-component]'));
        
        foreach ($components as $component) {
            if (!$component->parentNode) continue;
            
            $entityName = $component->getAttribute('k-component');
            $templateName = $component->getAttribute('k-template');
            $index = $component->getAttribute('k-index') ?? 0;
            
            $entity = new Entity('k_data', $entityName, $index);
            $template = file_get_contents("template/{$templateName}.html");
            
            $engine = new TemplateEngine($this->lang);
            $rendered = $engine->render($template, $entity);
            
            $imported = DomHelper::importHtml($dom, $rendered);
            $component->parentNode->replaceChild($imported, $component);
        }
    }
}