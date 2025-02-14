<?php

class TemplateManager {
    private $dom;
    private $templatePath;
    private $data;
    private $lang;

    public function __construct($templatePath, $data, $lang) {
        $this->templatePath = $templatePath;
        $this->data = $data;
        $this->lang = $lang;
        $this->initializeDom();
    }

    private function initializeDom() {
        $this->dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        if (!$this->dom->loadHTMLFile($this->templatePath, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            throw new Exception("Errore nel caricamento del template");
        }
    }

    public function disableInteractiveElements() {
        foreach ($this->dom->getElementsByTagName('button') as $btn) {
            $btn->setAttribute('disabled', 'disabled');
        }

        foreach ($this->dom->getElementsByTagName('a') as $link) {
            $link->removeAttribute('href');
            $link->setAttribute('style', 'pointer-events: none; color: gray;');
        }
    }

    public function processEditableContent() {
        foreach ($this->dom->getElementsByTagName('*') as $el) {
            if (!$el->hasAttribute('k-edit')) {
                continue;
            }
    
            switch ($el->tagName) {
                case 'img':
                    $this->updateImageEditableElement($el);
                    $el->setAttribute('onclick', 'editImage(event, this)');
                    $el->setAttribute('class', trim($el->getAttribute('class') . ' editable'));
                    break;
    
                default:
                    $this->updateTextEditableElement($el);
                    $el->setAttribute('onclick', 'editText(event, this)');
                    $el->setAttribute('class', trim($el->getAttribute('class') . ' editable'));
                    break;
            }
        }
    }
    

    private function updateTextEditableElement($element) {
        $id = $element->getAttribute('k-id');
        if (isset($this->data[$id][$this->lang])) {
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($this->dom->createTextNode($this->data[$id][$this->lang]));
        }
    }

    private function updateImageEditableElement($element) {
        $id = $element->getAttribute('k-id');
        if (isset($this->data[$id]['src'])) {
            $element->setAttribute('src', $this->data[$id]['src']);
        }
    }


    public function injectOverlay($overlayPath) {
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

    public function addResources() {
        $head = $this->dom->getElementsByTagName('head')->item(0);
        $body = $this->dom->getElementsByTagName('body')->item(0);

        // Aggiungi dati JSON
        $script = $this->dom->createElement('script');
        $script->textContent = 'window.kData = ' . json_encode($this->data, JSON_HEX_TAG | JSON_HEX_AMP);
        $head->appendChild($script);

        // Aggiungi CSS
        $css = $this->dom->createElement('link');
        $css->setAttribute('rel', 'stylesheet');
        $css->setAttribute('href', $GLOBALS['paths']['editor_css']);
        $head->appendChild($css);

        // Aggiungi JS
        $js = $this->dom->createElement('script');
        $js->setAttribute('src', $GLOBALS['paths']['editor_js']);
        $body->appendChild($js);
    }

    public function render() {
        libxml_clear_errors();
        return $this->dom->saveHTML();
    }
}