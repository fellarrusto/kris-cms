<?php
namespace Editor\Includes;

// load the Core classes
require_once __DIR__ . '/../../core/DataResolver.php';
require_once __DIR__ . '/../../core/ComponentLoader.php';

use Core\DataResolver;
use Core\ComponentLoader;
use DOMDocument;

class TemplateManager
{
    private DataResolver    $resolver;
    private ComponentLoader $loader;
    private DOMDocument     $dom;
    private string          $templatePath;
    private array           $paths;
    private string          $lang;

    public function __construct(
        string $templatePath,
        string $dataPath,
        string $componentsPath,
        array  $paths,
        string $lang = 'it'
    ) {
        $this->resolver     = new DataResolver($dataPath);
        $this->loader       = new ComponentLoader($componentsPath);
        $this->templatePath = $templatePath;
        $this->paths        = $paths;
        $this->lang         = $lang;
        $this->initializeDom();
    }

    private function initializeDom(): void
    {
        $this->dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (! $this->dom->loadHTMLFile(
            $this->templatePath,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        )) {
            throw new \Exception("Errore nel caricamento del template");
        }
    }

    public function injectComponents(): void
    {
        $html = $this->dom->saveHTML();

        $html = preg_replace_callback(
            '#<div([^>]*)k-template="([^"]+)"([^>]*)>\s*</div>#i',
            function ($m) {
                $compHtml = $this->loader->load($m[2]);
                return "<div{$m[1]}k-template=\"{$m[2]}\"{$m[3]}>" . $compHtml . "</div>";
            },
            $html
        );

        libxml_use_internal_errors(true);
        $newDom = new DOMDocument();
        $newDom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $this->dom = $newDom;
    }

    public function disableInteractiveElements(): void
    {
        foreach ($this->dom->getElementsByTagName('button') as $btn) {
            $btn->setAttribute('style', 'pointer-events: none; opacity: 0.5; cursor: not-allowed;');
        }
        foreach ($this->dom->getElementsByTagName('a') as $link) {
            $link->removeAttribute('href');
            $link->setAttribute('style', 'pointer-events: none; color: gray;');
        }
        foreach ($this->dom->getElementsByTagName('video') as $video) {
            if ($video->hasAttribute('controls')) {
                $video->removeAttribute('controls');
            }
            $video->setAttribute('preload', 'metadata');
        }
    }

    public function processEditableContent(): void
    {
        foreach ($this->dom->getElementsByTagName('*') as $el) {
            if (!($el->hasAttribute('k-edit') || $el->hasAttribute('k-component'))) {
                continue;
            }

            // repeatable components
            if ($el->hasAttribute('k-component')) {
                $id    = $el->getAttribute('k-id');
                $items = $this->resolver->getComponentData($id);
                if (!empty($items) && is_array($items)) {
                    $wrapper      = $el;
                    $templateNode = $wrapper->firstElementChild;
                    if ($templateNode && $templateNode->hasAttribute('k-item')) {
                        foreach ($items as $itemKey => $itemData) {
                            $clone = $templateNode->cloneNode(true);
                            $clone->setAttribute('k-id', $itemKey);
                            foreach ($clone->getElementsByTagName('*') as $field) {
                                if (! $field->hasAttribute('k-id')) continue;
                                $fid   = $field->getAttribute('k-id');
                                $entry = $itemData[$fid] ?? [];
                                if (in_array($field->tagName, ['img','video','iframe'], true) && !empty($entry['src'])) {
                                    $field->setAttribute('src', $entry['src']);
                                } elseif ($field->tagName === 'a' && !empty($entry['action'])) {
                                    $field->setAttribute('href', $entry['action']);
                                }
                                $text = $entry[$this->lang] ?? $entry['en'] ?? '';
                                if ($text !== '') {
                                    while ($field->firstChild) {
                                        $field->removeChild($field->firstChild);
                                    }
                                    $field->appendChild($this->dom->createTextNode($text));
                                }
                            }
                            $wrapper->appendChild($clone);
                        }
                        $wrapper->removeChild($templateNode);
                        // $el->removeAttribute('k-component');
                        // $el->removeAttribute('k-template');
                        // $el->removeAttribute('k-id');
                    }
                }
                $el->setAttribute('onclick', 'editRepeatable(event, this)');
                $el->setAttribute('class', trim($el->getAttribute('class') . ' editable'));
                continue;
            }

            // single editable elements
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

    private function updateTextEditableElement(\DOMElement $el): void
    {
        $id   = $el->getAttribute('k-id');
        $text = $this->resolver->get($id, $this->lang);
        if ($text !== null) {
            while ($el->firstChild) {
                $el->removeChild($el->firstChild);
            }
            $el->appendChild($this->dom->createTextNode($text));
        }
    }

    private function updateImageEditableElement(\DOMElement $el): void
    {
        $id  = $el->getAttribute('k-id');
        $src = $this->resolver->get($id, '', 'src');
        if ($src !== null) {
            $el->setAttribute('src', $src);
        }
    }

    private function updateVideoEditableElement(\DOMElement $el): void
    {
        $id  = $el->getAttribute('k-id');
        $src = $this->resolver->get($id, '', 'src');
        if ($src !== null) {
            $el->setAttribute('src', $src);
        }
    }

    public function injectOverlay(string $overlayPath): void
    {
        $overlayContent = file_get_contents($overlayPath);
        if ($overlayContent === false) {
            throw new \Exception("Overlay template non trovato");
        }
        $fragment = $this->dom->createDocumentFragment();
        if (! $fragment->appendXML($overlayContent)) {
            throw new \Exception("Errore nel parsing dell'overlay");
        }
        if ($body = $this->dom->getElementsByTagName('body')->item(0)) {
            $body->appendChild($fragment);
        }
    }

    public function addResources(): void
    {
        $head = $this->dom->getElementsByTagName('head')->item(0);
        $body = $this->dom->getElementsByTagName('body')->item(0);

        // Embed JSON data via resolver->all()
        $script = $this->dom->createElement('script');
        $json   = json_encode($this->resolver->all(), JSON_HEX_TAG | JSON_HEX_AMP);
        $script->textContent = "window.kData = {$json}";
        $head->appendChild($script);

        // CSS
        $css = $this->dom->createElement('link');
        $css->setAttribute('rel', 'stylesheet');
        $css->setAttribute('href', $this->paths['editor_css']);
        $head->appendChild($css);

        // JS
        $js = $this->dom->createElement('script');
        $js->setAttribute('src', $this->paths['editor_js']);
        $body->appendChild($js);
    }

    public function render(): string
    {
        libxml_clear_errors();
        return $this->dom->saveHTML();
    }
}
