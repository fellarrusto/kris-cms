<?php
declare(strict_types=1);

namespace Kris\Template;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Il filtro dei testi formattati (docs/2.0/specifica.md, 2.5): restano solo
 * gli elementi e gli attributi di un testo editoriale, il resto si toglie.
 * Si applica al salvataggio (editor, staging, agente) e al rendering, così
 * anche un valore scritto a mano nei dati non porta script nella pagina.
 *
 * Un elemento non ammesso lascia il suo testo (un <div> diventa il suo
 * contenuto); script, stili, iframe e simili spariscono con quello che
 * contengono. Un tema che ha bisogno di HTML libero (un embed) usa un campo
 * text e lo inserisce nel template.
 */
final class Richtext
{
    private const ELEMENTS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'code', 'pre', 'span', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr'];

    /** Elementi che spariscono con il loro contenuto. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form', 'input',
        'button', 'select', 'textarea', 'frame', 'frameset', 'link', 'meta', 'base', 'title', 'head'];

    private const ATTRIBUTES = [
        '*' => ['class', 'title'],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
    ];

    public static function clean(string $html): string
    {
        if ($html === '' || !preg_match('/[<&]/', $html)) return $html;
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="kris-richtext">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('kris-richtext');
        if ($root === null) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $child) $out .= $dom->saveHTML($child);
        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROPPED, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child);
            if (!in_array($tag, self::ELEMENTS, true)) {
                // Resta il contenuto, senza l'elemento
                while ($child->firstChild !== null) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $allowed = array_merge(self::ATTRIBUTES['*'], self::ATTRIBUTES[$tag] ?? []);
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $keep = in_array($name, $allowed, true) && match ($name) {
                    'href' => self::safeHref($attr->value),
                    'src' => self::safeSrc($attr->value),
                    'target' => in_array($attr->value, ['_blank', '_self'], true),
                    'colspan', 'rowspan' => ctype_digit($attr->value),
                    default => true,
                };
                if (!$keep) $child->removeAttribute($attr->name);
            }
            // Un link in una nuova scheda non dà accesso alla pagina che lo apre
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $rel = array_filter(preg_split('/\s+/', strtolower($child->getAttribute('rel'))) ?: []);
                $child->setAttribute('rel', implode(' ', array_unique(array_merge($rel, ['noopener']))));
            }
        }
    }

    /** http, https, mailto, tel, indirizzi relativi e ancore. */
    private static function safeHref(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return false;
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m) === 1) return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true);
        return true;
    }

    /** Immagini del sito (indirizzi relativi) o https. */
    private static function safeSrc(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '//') || preg_match('/[\x00-\x1F\x7F]/', $url)) return false;
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m) === 1) return strtolower($m[1]) === 'https';
        return true;
    }
}
