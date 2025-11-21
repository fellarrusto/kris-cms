<?php
 
require_once __DIR__ . '/DomHelper.php';
require_once __DIR__ . '/ArrayProcessor.php';
require_once __DIR__ . '/ComponentProcessor.php';
require_once __DIR__ . '/ElementProcessor.php';
require_once __DIR__ . '/../entity/Entity.php';
require_once __DIR__ . '/../entity/JsonRepository.php';

class TemplateEngine {
    private $lang;
    private $arrayProcessor;
    private $componentProcessor;
    private $elementProcessor;

    public function __construct($lang = 'it') {
        $this->lang = $lang;
        $this->arrayProcessor = new ArrayProcessor($lang);
        $this->componentProcessor = new ComponentProcessor($lang);
        $this->elementProcessor = new ElementProcessor($lang);
    }

    public function render($html, $entity) {
        $this->elementProcessor->process($html, $entity);
        
        $dom = DomHelper::loadHtml($html);
        
        $this->arrayProcessor->process($dom);
        $this->componentProcessor->process($dom);

        return $dom->saveHTML();
    }
}