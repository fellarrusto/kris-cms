<?php
declare(strict_types=1);

namespace Kris\Entity;

use Exception;
use Kris\Store\Site;

/**
 * Un contenuto (o un elemento di un elenco) come lo legge il rendering:
 * valori per nome e definizioni dei campi del formato 2
 * (docs/2.0/specifica.md, 2). I dati vengono da Kris\Store\Site, con
 * l'anteprima dello staging quando è attiva.
 */
class Entity
{
    /**
     * @param array<string, mixed> $values valori per nome
     * @param array<string, array> $fields definizioni dei campi per nome
     */
    public function __construct(private string $type, private int $id, private array $values = [], private array $fields = [])
    {
    }

    /** Il contenuto $id del tipo; non controlla la visibilità (componenti, sezioni riservate). */
    public static function load(string $type, int $id = 0): self
    {
        $site = Site::load(null, true);
        foreach ($site['content'] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === $type && (int) ($item['id'] ?? -1) === $id) {
                return self::of($item, $type, Site::fields($site['schema'], $type));
            }
        }
        throw new Exception("Entity {$type} with id {$id} not found");
    }

    /** Il primo contenuto del tipo, o null: per {{tipo.campo}}. */
    public static function first(string $type): ?self
    {
        $site = Site::load(null, true);
        foreach ($site['content'] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === $type) return self::of($item, $type, Site::fields($site['schema'], $type));
        }
        return null;
    }

    /** @return list<self> i contenuti visibili del tipo, nell'ordine */
    public static function all(string $type): array
    {
        $site = Site::load(null, true);
        $fields = Site::fields($site['schema'], $type);
        $out = [];
        foreach ($site['content'] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === $type && !self::isHidden($item)) $out[] = self::of($item, $type, $fields);
        }
        return $out;
    }

    /**
     * Un contenuto visibile, o un elemento dei suoi elenchi: $path alterna
     * nome dell'elenco e id ('features', '0', 'highlights', '2'). Un
     * elemento sospeso, o dentro un elemento sospeso, non c'è.
     */
    public static function fromPath(string $type, int $id, array $path): self
    {
        $entity = self::load($type, $id);
        if ($entity->hidden) throw new Exception("Entity {$type} with id {$id} is hidden");
        for ($i = 0, $n = count($path); $i < $n; $i += 2) {
            $list = (string) $path[$i];
            if (!isset($path[$i + 1])) throw new Exception("Invalid path: field '{$list}' has no id");
            $found = null;
            foreach ($entity->children($list) ?? [] as $child) {
                if ($child->id === (int) $path[$i + 1]) $found = $child;
            }
            if ($found === null) throw new Exception("Path not found: {$list}/{$path[$i + 1]}");
            $entity = $found;
        }
        return $entity;
    }

    private bool $hidden = false;

    private static function of(array $item, string $type, array $fields): self
    {
        $entity = new self($type, (int) ($item['id'] ?? 0), is_array($item['values'] ?? null) ? $item['values'] : [], $fields);
        $entity->hidden = self::isHidden($item);
        return $entity;
    }

    /**
     * Vero se l'elemento (contenuto o elemento di un elenco) è sospeso: resta
     * nei dati e nell'editor, ma il sito non lo mostra.
     */
    public static function isHidden(array $item): bool
    {
        return ($item['hidden'] ?? false) === true;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function id(): int
    {
        return $this->id;
    }

    /**
     * Gli elementi visibili di un elenco, o null se il contenuto non ha un
     * elenco con quel nome (k-array cerca allora il tipo omonimo).
     *
     * @return list<self>|null
     */
    public function children(string $name): ?array
    {
        $def = $this->fields[$name] ?? null;
        $value = $this->values[$name] ?? null;
        if (($def['type'] ?? null) !== 'list' && !(is_array($value) && array_is_list($value) && $def === null && $value !== [])) return null;
        $of = is_array($def['of'] ?? null) ? array_column(array_filter($def['of'], 'is_array'), null, 'name') : [];
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item) && !self::isHidden($item)) $out[] = self::of($item, $name, $of);
        }
        return $out;
    }

    /**
     * Il valore di un campo nella lingua: per un campo localizzato la
     * traduzione, o la prima non vuota se manca (la stringa "0" è un valore).
     * Un interruttore vale "1" o "" e non ripiega su un'altra lingua; una
     * scelta multipla le opzioni separate da virgole. Senza lingua, un campo
     * localizzato restituisce i valori per lingua.
     */
    public function getData(string $name, ?string $lang = null): mixed
    {
        if ($name === 'id') return $this->id;
        if (!array_key_exists($name, $this->values)) return null;
        $value = $this->values[$name];
        $type = $this->fields[$name]['type'] ?? null;
        if ($type === 'list' || (is_array($value) && array_is_list($value) && $type !== 'select')) return $value;
        if (is_array($value) && !array_is_list($value)) {
            if ($lang === null) return $value;
            if ($type === 'boolean') return self::scalar($type, $value[$lang] ?? false);
            if (isset($value[$lang]) && $value[$lang] !== '' && $value[$lang] !== []) return self::scalar($type, $value[$lang]);
            foreach ($value as $v) {
                if ($v !== null && $v !== '' && $v !== []) return self::scalar($type, $v);
            }
            return null;
        }
        return self::scalar($type, $value);
    }

    /** Tipo del campo nello schema ('text', 'richtext', 'media', 'select', 'boolean', 'list'). */
    public function getType(string $name): ?string
    {
        return $this->fields[$name]['type'] ?? null;
    }

    private static function scalar(?string $type, mixed $value): mixed
    {
        if ($type === 'boolean' || is_bool($value)) return $value === true || $value === '1' ? '1' : '';
        if (is_array($value)) return implode(',', array_map('strval', $value));
        return $value;
    }

    /**
     * Vero se il campo del formato 1 ha un solo valore per tutte le lingue:
     * plain sempre, interruttore e scelta salvo "localized": true.
     * Formato 1: lo usano editor e staging finché non passano al formato 2.
     */
    public static function isShared(array $def): bool
    {
        $type = $def['type'] ?? '';
        return $type === 'plain' || (in_array($type, ['boolean', 'select'], true) && empty($def['localized']));
    }

    /**
     * Il valore da salvare nel formato 1 per un interruttore ("1" o "") o una
     * scelta (le opzioni ammesse, separate da virgole se multipla).
     */
    public static function normalizeChoice(array $def, mixed $value): mixed
    {
        if (($def['type'] ?? '') === 'boolean') {
            return in_array($value, [true, 1, '1', 'true', 'on'], true) ? '1' : '';
        }
        if (($def['type'] ?? '') !== 'select') return $value;
        $picked = is_array($value) ? $value : explode(',', (string) $value);
        $options = array_map('strval', (array) ($def['options'] ?? []));
        $picked = array_values(array_intersect($options, array_map(fn($v) => trim((string) $v), $picked)));
        return implode(',', !empty($def['multiple']) ? $picked : array_slice($picked, 0, 1));
    }
}
