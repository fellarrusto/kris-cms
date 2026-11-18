<?php
declare(strict_types=1);

namespace Kris\Template;

use Kris\Entity\Entity;
use Kris\Site\Paths;
use DOMXPath;
use DOMDocument;

class ArrayProcessor {
    private string $lang;

    public function __construct(string $lang) {
        $this->lang = $lang;
    }

    public function process(DOMDocument $dom, ?Entity $parent = null): void {
        $xpath = new DOMXPath($dom);
        $arrays = iterator_to_array($xpath->query('//*[@k-array]'));

        foreach ($arrays as $array) {
            $entityName = $array->getAttribute('k-array');
            $templateName = $array->getAttribute('k-template');
            $template = file_get_contents(Paths::template($templateName));

            $entities = $this->resolveEntities($entityName, $parent);

            foreach ($entities as $entity) {
                $engine = new TemplateEngine($this->lang);
                $rendered = $engine->render($template, $entity);

                $imported = DomHelper::importHtml($dom, $rendered);
                $array->appendChild($imported);
            }

            $array->removeAttribute('k-array');
            $array->removeAttribute('k-template');
        }
    }

    /**
     * Elementi da mostrare: un elenco del contenuto corrente, altrimenti i
     * contenuti del tipo omonimo. Quelli sospesi dall'editor restano fuori.
     *
     * @return list<Entity>
     */
    private function resolveEntities(string $name, ?Entity $parent): array {
        return $parent?->children($name) ?? Entity::all($name);
    }
}
