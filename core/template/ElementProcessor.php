<?php
declare(strict_types=1);

namespace Kris\Template;

use Exception;
use Kris\Entity\Entity;

class ElementProcessor
{
    private string $lang;

    public function __construct(string $lang)
    {
        $this->lang = $lang;
    }

    public function process(string &$html, Entity $entity): void
    {
        $tokens = $this->tokenize($html);
        $tree = $this->parse($tokens);
        $html = $this->evaluate($tree, $entity);
    }

    private function tokenize(string $html): array
    {
        $tokens = [];
        $offset = 0;

        while (($pos = strpos($html, '{{', $offset)) !== false) {
            if ($pos > $offset) {
                $tokens[] = ['type' => 'text', 'value' => substr($html, $offset, $pos - $offset)];
            }

            $end = strpos($html, '}}', $pos);
            if ($end === false) break;

            $tag = trim(substr($html, $pos + 2, $end - $pos - 2));
            $offset = $end + 2;

            if (preg_match('/^#if\s+(\w+)\s*(==|!=|>|<|>=|<=)\s*(.+)$/', $tag, $m)) {
                $tokens[] = ['type' => 'if', 'field' => $m[1], 'op' => $m[2], 'value' => trim($m[3])];
            } elseif (preg_match('/^#elif\s+(\w+)\s*(==|!=|>|<|>=|<=)\s*(.+)$/', $tag, $m)) {
                $tokens[] = ['type' => 'elif', 'field' => $m[1], 'op' => $m[2], 'value' => trim($m[3])];
            } elseif ($tag === '#else') {
                $tokens[] = ['type' => 'else'];
            } elseif ($tag === '/if') {
                $tokens[] = ['type' => 'endif'];
            } else {
                $tokens[] = ['type' => 'variable', 'name' => $tag];
            }
        }

        if ($offset < strlen($html)) {
            $tokens[] = ['type' => 'text', 'value' => substr($html, $offset)];
        }

        return $tokens;
    }

    private function parse(array $tokens): array
    {
        $root = ['type' => 'root', 'children' => []];
        $stack = [&$root];

        foreach ($tokens as $token) {
            $current = &$stack[count($stack) - 1];

            switch ($token['type']) {
                case 'if':
                    $node = [
                        'type' => 'conditional',
                        'branches' => [
                            ['field' => $token['field'], 'op' => $token['op'], 'value' => $token['value'], 'children' => []]
                        ],
                        'else' => null
                    ];
                    $current['children'][] = &$node;
                    $stack[] = &$node;
                    unset($node);
                    break;

                case 'elif':
                    $current['branches'][] = [
                        'field' => $token['field'], 'op' => $token['op'], 'value' => $token['value'], 'children' => []
                    ];
                    break;

                case 'else':
                    $current['else'] = [];
                    break;

                case 'endif':
                    array_pop($stack);
                    break;

                default:
                    // text o variable — appendono al ramo attivo del conditional, oppure ai children del nodo corrente
                    if ($current['type'] === 'conditional') {
                        if ($current['else'] !== null) {
                            $current['else'][] = $token;
                        } else {
                            $branch = &$current['branches'][count($current['branches']) - 1];
                            $branch['children'][] = $token;
                            unset($branch);
                        }
                    } else {
                        $current['children'][] = $token;
                    }
                    break;
            }
        }

        return $root;
    }

    private function evaluate(array $node, Entity $entity): string
    {
        $out = '';

        $children = $node['children'] ?? [];
        foreach ($children as $child) {
            switch ($child['type']) {
                case 'text':
                    $out .= $child['value'];
                    break;

                case 'variable':
                    $name = $child['name'];
                    $out .= ($name === 'language') ? $this->lang : ($entity->getData($name, $this->lang) ?? '');
                    break;

                case 'conditional':
                    $out .= $this->evaluateConditional($child, $entity);
                    break;
            }
        }

        return $out;
    }

    private function evaluateConditional(array $node, Entity $entity): string
    {
        foreach ($node['branches'] as $branch) {
            $fieldValue = ($branch['field'] === 'language') ? $this->lang : $entity->getData($branch['field']);

            if ($fieldValue !== null && $this->compare($fieldValue, $branch['op'], $branch['value'])) {
                return $this->evaluate(['children' => $branch['children']], $entity);
            }
        }

        if ($node['else'] !== null) {
            return $this->evaluate(['children' => $node['else']], $entity);
        }

        return '';
    }

    private function compare(mixed $a, string $op, string $b): bool
    {
        $b = trim($b, '"\'');

        switch ($op) {
            case '==': return $a == $b;
            case '!=': return $a != $b;
            case '>':  return $a > $b;
            case '<':  return $a < $b;
            case '>=': return $a >= $b;
            case '<=': return $a <= $b;
            default: throw new Exception("Invalid operator '{$op}'");
        }
    }
}