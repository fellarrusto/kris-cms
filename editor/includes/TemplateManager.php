<?php

require_once __DIR__ . '/../../helpers/replace_components.php';
class TemplateManager
{
    private $dom;
    private $templatePath;
    private $data;
    private $lang;
    private $paths;

    public function __construct($templatePath, $data, $lang, $paths)
    {
        $this->templatePath = $templatePath;
        $this->data = $data;
        $this->lang = $lang;
        $this->paths = $paths;
        $this->initializeDom();
    }

    private function initializeDom()
    {
        $this->dom = new DOMDocument();
        libxml_use_internal_errors(true);

        if (!$this->dom->loadHTMLFile($this->templatePath, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            throw new Exception("Errore nel caricamento del template");
        }
    }

    public function injectComponents(): void
    {
        $html = $this->dom->saveHTML();

        $html = preg_replace_callback(
            '#<div([^>]*)k-template="([^"]+)"([^>]*)>\s*</div>#i',
            function ($matches) {
                $templateName = $matches[2];
                $componentPath = '../templates/components/' . $templateName . '.html';

                if (!file_exists($componentPath)) {
                    // Component not found, return the original empty div
                    return $matches[0];
                }

                $componentHtml = file_get_contents($componentPath);

                // Rebuild the <div> with the original attributes and injected component HTML
                return "<div{$matches[1]}k-template=\"{$templateName}\"{$matches[3]}>"
                    . $componentHtml
                    . "</div>";
            },
            $html
        );

        // Reload DOM with the updated HTML
        libxml_use_internal_errors(true);
        $newDom = new \DOMDocument();
        $newDom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $this->dom = $newDom;
    }

    public function disableInteractiveElements()
    {
        foreach ($this->dom->getElementsByTagName('button') as $btn) {
            $btn->setAttribute('style', 'pointer-events: none; opacity: 0.5; cursor: not-allowed;');
        }

        foreach ($this->dom->getElementsByTagName('a') as $link) {
            $link->removeAttribute('href');
            $link->setAttribute('style', 'pointer-events: none; color: gray;');
        }
    }

    public function processEditableContent()
    {
        foreach ($this->dom->getElementsByTagName('*') as $el) {
            if (! ($el->hasAttribute('k-edit') || $el->hasAttribute('k-component')) ) {
                continue;
            }

            if ($el->hasAttribute('k-component')) {
                $id = $el->getAttribute('k-id');
                if (isset($this->data[$id])) {
                   replaceComponents($el, $this->data, $this->dom, $this->lang); 
                }
                
                $el->setAttribute('onclick', 'editRepeatable(event, this)');
                $el->setAttribute('class', trim($el->getAttribute('class') . ' editable'));
                continue;
            }

            switch ($el->tagName) {
               case 'img':
                   $this->updateImageEditableElement($el);
                   $el->setAttribute('onclick', 'editImage(event, this)');
                   break;

                case 'video':
                    $this->updateVideoEditableElement($el);
                    $el->setAttribute('onclick', 'editVideo(event, this)');
                    break;

                case 'a':
                case 'button':
                    $this->updateTextEditableElement($el);
                    $el->setAttribute('onclick', 'editButton(event, this)');
                    break;

                default:
                    $this->updateTextEditableElement($el);
                    $el->setAttribute('onclick', 'editText(event, this)');
                    break;
            }

            $el->setAttribute('class', trim($el->getAttribute('class') . ' editable'));
        }
    }

    private function updateTextEditableElement($element)
    {
        $id = $element->getAttribute('k-id');
        if (isset($this->data[$id][$this->lang])) {
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($this->dom->createTextNode($this->data[$id][$this->lang]));
        }
    }

    private function updateImageEditableElement($element)
    {
        $id = $element->getAttribute('k-id');
        if (isset($this->data[$id]['src'])) {
            $element->setAttribute('src', $this->data[$id]['src']);
        }
    }

    private function updateVideoEditableElement($element)
    {
        $id = $element->getAttribute('k-id');
        if (isset($this->data[$id]['src'])) {
            $element->setAttribute('src', $this->data[$id]['src']);
        }
    }


    public function injectOverlay($overlayPath)
    {
        $overlayContent = file_get_contents($overlayPath);
        if ($overlayContent === false) {
            throw new Exception("Overlay template non trovato");
        }

        $fragment = $this->dom->createDocumentFragment();
        if (!$fragment->appendXML($overlayContent)) {
            throw new Exception("Errore nel parsing dell'overlay");
        }

        if ($body = $this->dom->getElementsByTagName('body')->item(0)) {
            $body->appendChild($fragment);
        }
    }

    public function addResources()
    {
        $head = $this->dom->getElementsByTagName('head')->item(0);
        $body = $this->dom->getElementsByTagName('body')->item(0);

        // Aggiungi dati JSON
        $script = $this->dom->createElement('script');
        $script->textContent = 'window.kData = ' . json_encode($this->data, JSON_HEX_TAG | JSON_HEX_AMP);
        $head->appendChild($script);

        // Aggiungi CSS
        $css = $this->dom->createElement('link');
        $css->setAttribute('rel', 'stylesheet');
        $css->setAttribute('href', $this->paths['editor_css']);
        $head->appendChild($css);

        // Aggiungi JS
        $js = $this->dom->createElement('script');
        $js->setAttribute('src', $this->paths['editor_js']);
        $body->appendChild($js);
    }

    public function render()
    {
        libxml_clear_errors();
        return $this->dom->saveHTML();
    }
}
