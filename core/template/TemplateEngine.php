<?php
namespace Kris\Template;

use Kris\Entity\Entity;
use Kris\Entity\JsonRepository;

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