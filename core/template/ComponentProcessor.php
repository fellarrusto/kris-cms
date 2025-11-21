<?php
declare(strict_types=1);

namespace Kris\Template;

use Kris\Entity\Entity;
use DOMXPath;
use DOMDocument;

class ComponentProcessor {
    private string $lang;

    public function __construct(string $lang) {
        $this->lang = $lang;
    }

    public function process(DOMDocument $dom): void {
        $xpath = new DOMXPath($dom);
        $components = iterator_to_array($xpath->query('//*[@k-component]'));

        foreach ($components as $component) {
            if (!$component->parentNode) continue;

            $entityName = $component->getAttribute('k-component');
            $templateName = $component->getAttribute('k-template');
            $index = (int)($component->getAttribute('k-index') ?: 0);

            $entity = new Entity('k_data', $entityName, $index);
            $template = file_get_contents("template/{$templateName}.html");

            $engine = new TemplateEngine($this->lang);
            $rendered = $engine->render($template, $entity);

            $imported = DomHelper::importHtml($dom, $rendered);
            $component->parentNode->replaceChild($imported, $component);
        }
    }
}