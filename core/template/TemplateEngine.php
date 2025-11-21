<?php
declare(strict_types=1);

namespace Kris\Template;

use Kris\Entity\Entity;

class TemplateEngine {
    private string $lang;
    private ArrayProcessor $arrayProcessor;
    private ComponentProcessor $componentProcessor;
    private ElementProcessor $elementProcessor;

    public function __construct(string $lang = 'it') {
        $this->lang = $lang;
        $this->arrayProcessor = new ArrayProcessor($lang);
        $this->componentProcessor = new ComponentProcessor($lang);
        $this->elementProcessor = new ElementProcessor($lang);
    }

    public function render(string $html, Entity $entity): string {
        $this->elementProcessor->process($html, $entity);

        $dom = DomHelper::loadHtml($html);

        $this->arrayProcessor->process($dom);
        $this->componentProcessor->process($dom);

        return $dom->saveHTML();
    }
}