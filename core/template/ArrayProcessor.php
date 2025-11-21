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

    public function process(DOMDocument $dom): void {
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