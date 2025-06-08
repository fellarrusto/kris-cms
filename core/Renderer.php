<?php
namespace Core;

use DOMDocument;

class Renderer {
    private DataResolver $resolver;
    private ComponentLoader $loader;
    private string $defaultLang;

    public function __construct(
        DataResolver $resolver,
        ComponentLoader $loader,
        string $lang = 'it'
    ) {
        $this->resolver    = $resolver;
        $this->loader      = $loader;
        $this->defaultLang = $lang;
    }

    public function render(string $templatePath, ?string $lang = null): string {
        $lang = $lang ?? $this->defaultLang;
        $html = file_get_contents($templatePath);
        $html = $this->injectComponents($html);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        foreach ($dom->getElementsByTagName('*') as $el) {
            if ($el->hasAttribute('k-edit')) {
                $this->applyEdit($dom, $el, $lang);
            }
            if ($el->hasAttribute('k-component')) {
                $this->applyComponent($dom, $el, $lang);
            }
        }

        return $dom->saveHTML();
    }

    private function injectComponents(string $html): string {
        return preg_replace_callback(
            '#<div([^>]*)k-template="([^"]+)"([^>]*)>\s*</div>#i',
            function ($m) {
                $compHtml = $this->loader->load($m[2]);
                return "<div{$m[1]}k-template=\"{$m[2]}\"{$m[3]}>"
                     . $compHtml
                     . "</div>";
            },
            $html
        );
    }

    private function applyEdit(DOMDocument $dom, \DOMElement $el, string $lang): void {
        $id  = $el->getAttribute('k-id');
        $tag = $el->tagName;

        if (in_array($tag, ['img','video','iframe'], true)) {
            $src = $this->resolver->get($id, '', 'src');
            if ($src) {
                $el->setAttribute('src', $src);
            }
            return;
        }

        if (in_array($tag, ['a','button'], true)) {
            $action = $this->resolver->get($id, $lang, 'action');
            if ($action) {
                if ($tag === 'a') {
                    $el->setAttribute('href', $action);
                } else {
                    $el->setAttribute('onclick', "window.location.href='{$action}'");
                }
            }
        }

        $text = $this->resolver->get($id, $lang);
        if ($text) {
            while ($el->firstChild) {
                $el->removeChild($el->firstChild);
            }
            $el->appendChild($dom->createTextNode($text));
        }
    }

    private function applyComponent(DOMDocument $dom, \DOMElement $el, string $lang): void {
        $id    = $el->getAttribute('k-id');
        $items = $this->resolver->getComponentData($id);
        if (empty($items) || !is_array($items)) {
            return;
        }

        // Il wrapper è il <div k-component> stesso
        $wrapper = $el;

        // Uso il primo elemento figlio come template (deve avere k-item)
        $templateNode = $wrapper->firstElementChild;
        if (!$templateNode || !$templateNode->hasAttribute('k-item')) {
            return;
        }

        // Per ogni record JSON, clono e popolo
        foreach ($items as $itemKey => $itemData) {
            $clone = $templateNode->cloneNode(true);
            $clone->setAttribute('k-id', $itemKey);

            foreach ($clone->getElementsByTagName('*') as $field) {
                if (!$field->hasAttribute('k-id')) {
                    continue;
                }
                $fid   = $field->getAttribute('k-id');
                $entry = $itemData[$fid] ?? [];

                if (in_array($field->tagName, ['img','video','iframe'], true) && !empty($entry['src'])) {
                    $field->setAttribute('src', $entry['src']);
                } elseif ($field->tagName === 'a' && !empty($entry['action'])) {
                    $field->setAttribute('href', $entry['action']);
                }

                $text = $entry[$lang] ?? $entry['en'] ?? '';
                if ($text) {
                    $field->nodeValue = $text;
                }
            }

            $wrapper->appendChild($clone);
        }

        // Rimuovo il template originale e gli attributi custom
        $wrapper->removeChild($templateNode);
        // $el->removeAttribute('k-component');
        // $el->removeAttribute('k-template');
        // $el->removeAttribute('k-id');
    }
}
