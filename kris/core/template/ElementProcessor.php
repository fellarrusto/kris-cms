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

            // Gli operatori a due caratteri vanno prima di quelli a uno:
            // altrimenti '>' matcherebbe la prima meta di '>=' e il resto
            // finirebbe nel valore da confrontare.
            if (preg_match('/^#if\s+([\w.]+)\s*(==|!=|>=|<=|>|<|has)\s*(.+)$/', $tag, $m)) {
                $tokens[] = ['type' => 'if', 'field' => $m[1], 'op' => $m[2], 'value' => trim($m[3])];
            } elseif (preg_match('/^#elif\s+([\w.]+)\s*(==|!=|>=|<=|>|<|has)\s*(.+)$/', $tag, $m)) {
                $tokens[] = ['type' => 'elif', 'field' => $m[1], 'op' => $m[2], 'value' => trim($m[3])];
            } elseif (preg_match('/^#(if|elif)\s+([\w.]+)$/', $tag, $m)) {
                // {{#if campo}}: vero se il campo ha un valore (un interruttore acceso, un testo non vuoto)
                $tokens[] = ['type' => $m[1], 'field' => $m[2], 'op' => 'set', 'value' => ''];
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
                    // Se siamo dentro un altro if, il nuovo nodo appartiene al ramo
                    // attivo: appenderlo a $current['children'] lo renderebbe
                    // irraggiungibile, perche evaluate() legge solo i rami.
                    if ($current['type'] === 'conditional') {
                        if ($current['else'] !== null) {
                            $current['else'][] = &$node;
                        } else {
                            $branch = &$current['branches'][count($current['branches']) - 1];
                            $branch['children'][] = &$node;
                            unset($branch);
                        }
                    } else {
                        $current['children'][] = &$node;
                    }
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
                    $out .= $this->renderValue($child['name'], $entity);
                    break;

                case 'conditional':
                    $out .= $this->evaluateConditional($child, $entity);
                    break;
            }
        }

        return $out;
    }

    /**
     * Rende il valore di {{campo}}.
     *
     * L'HTML e ammesso solo dove e voluto, cioe nei campi 'richtext'. Tutto
     * il resto viene escapato: cosi un testo con < o " non puo alterare il
     * markup ne uscire da un attributo, e i tipi dello schema hanno finalmente
     * un effetto concreto sul rendering.
     */
    private function renderValue(string $name, Entity $entity): string
    {
        if ($name === 'language') {
            return htmlspecialchars($this->lang, ENT_QUOTES, 'UTF-8');
        }

        [$entity, $name] = $this->resolve($name, $entity);
        if ($entity === null) return '';
        $value = $entity->getData($name, $this->lang);
        if ($value === null || \is_array($value)) {
            return '';
        }

        return $entity->getType($name) === 'richtext'
            ? (string) $value
            : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * {{tipo.campo}}: un campo del primo contenuto di un'altra raccolta (le
     * impostazioni, il footer), da qualunque template. Senza punto, il
     * contenuto corrente.
     *
     * @return array{0: ?Entity, 1: string}
     */
    /** @var array<string, ?Entity> i contenuti delle altre raccolte letti in questo rendering */
    private array $globals = [];

    private function resolve(string $name, Entity $entity): array
    {
        if (!str_contains($name, '.')) return [$entity, $name];
        [$type, $field] = explode('.', $name, 2);
        if (!array_key_exists($type, $this->globals)) {
            $first = array_values((new \Kris\Entity\JsonRepository())->findAll('k_data', $type))[0] ?? null;
            $this->globals[$type] = $first === null ? null : Entity::fromArray($first, $type);
        }
        return [$this->globals[$type], $field];
    }

    private function evaluateConditional(array $node, Entity $entity): string
    {
        foreach ($node['branches'] as $branch) {
            // Stessa risoluzione linguistica dell'interpolazione: senza lingua
            // un campo multilingua tornerebbe come array e il confronto con una
            // stringa sarebbe sempre falso.
            [$source, $field] = $this->resolve($branch['field'], $entity);
            $fieldValue = ($branch['field'] === 'language')
                ? $this->lang
                : ($source === null ? null : $source->getData($field, $this->lang));

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
            case 'has':
                $tags = array_map('trim', explode(',', (string)$a));
                return in_array($b, $tags);
            case 'set': return $a !== '' && $a !== '0' && $a !== false;
            default: throw new Exception("Invalid operator '{$op}'");
        }
    }
}