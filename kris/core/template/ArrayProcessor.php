<?php
declare(strict_types=1);

namespace Kris\Template;

use Kris\Entity\Entity;
use Kris\Entity\JsonRepository;
use DOMXPath;
use DOMDocument;

class ArrayProcessor {
    private string $lang;
    private JsonRepository $repo;

    public function __construct(string $lang) {
        $this->lang = $lang;
        $this->repo = new JsonRepository();
    }

    public function process(DOMDocument $dom, ?Entity $parent = null): void {
        $xpath = new DOMXPath($dom);
        $arrays = iterator_to_array($xpath->query('//*[@k-array]'));

        foreach ($arrays as $array) {
            $entityName = $array->getAttribute('k-array');
            $templateName = $array->getAttribute('k-template');
            $template = file_get_contents(KRIS_ROOT . "/template/{$templateName}.html");

            $entities = $this->resolveEntities($entityName, $parent);

            foreach ($entities as $entityData) {
                $entity = Entity::fromArray($entityData, $entityName);
                $engine = new TemplateEngine($this->lang);
                $rendered = $engine->render($template, $entity);

                $imported = DomHelper::importHtml($dom, $rendered);
                $array->appendChild($imported);
            }

            $array->removeAttribute('k-array');
            $array->removeAttribute('k-template');
        }
    }

    /** Elementi da mostrare: quelli sospesi dall'editor restano fuori. */
    private function resolveEntities(string $name, ?Entity $parent): array {
        $items = null;
        if ($parent !== null) {
            $items = $parent->getArray($name);
        }
        $items ??= $this->repo->findAll('k_data', $name);
        return array_values(array_filter($items, fn($item) => is_array($item) && !Entity::isHidden($item)));
    }
}
