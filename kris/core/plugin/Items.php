<?php
declare(strict_types=1);

namespace Kris\Plugin;

use Kris\Store\Site;
use Kris\Store\Stores;

/**
 * I contenuti di un tipo di un plugin, nell'archivio del sito come gli altri
 * (nessun file proprio in data/, nessuna tabella propria). Un contenuto è
 * {id, hidden?, values}; i valori per nome, come in content.json.
 */
final class Items
{
    /** Il documento in uso dentro atomic(): le scritture finiscono lì e si salvano alla fine. */
    private static ?array $open = null;

    public function __construct(private string $type)
    {
    }

    public function get(int $id): ?array
    {
        foreach ($this->content() as $item) {
            if (($item['type'] ?? null) === $this->type && (int) ($item['id'] ?? -1) === $id) return self::out($item);
        }
        return null;
    }

    /**
     * I contenuti del tipo nell'ordine; $where filtra su valori uguali.
     *
     * @return list<array{id: int, hidden: bool, values: array}>
     */
    public function list(array $where = [], ?int $limit = null, int $offset = 0): array
    {
        $out = [];
        foreach ($this->content() as $item) {
            if (($item['type'] ?? null) !== $this->type) continue;
            foreach ($where as $k => $v) {
                if (($item['values'][$k] ?? null) !== $v) continue 2;
            }
            $out[] = self::out($item);
        }
        return array_slice($out, $offset, $limit);
    }

    /** Crea (senza id) o aggiorna; restituisce il contenuto salvato. */
    public function save(array $item): array
    {
        $values = is_array($item['values'] ?? null) ? $item['values'] : [];
        $saved = null;
        $this->write(function (array $content) use ($item, $values, &$saved): array {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id === null) {
                $id = 0;
                foreach ($content as $c) {
                    if (($c['type'] ?? null) === $this->type) $id = max($id, (int) $c['id'] + 1);
                }
            }
            $new = ['type' => $this->type, 'id' => $id, 'values' => $values];
            if (!empty($item['hidden'])) $new['hidden'] = true;
            foreach ($content as $i => $c) {
                if (($c['type'] ?? null) === $this->type && (int) ($c['id'] ?? -1) === $id) {
                    $content[$i] = $new;
                    $saved = self::out($new);
                    return $content;
                }
            }
            $content[] = $new;
            $saved = self::out($new);
            return $content;
        });
        return $saved;
    }

    public function delete(int $id): void
    {
        $this->write(fn(array $content) => array_values(array_filter($content,
            fn($c) => !(($c['type'] ?? null) === $this->type && (int) ($c['id'] ?? -1) === $id))));
    }

    /**
     * Più letture e scritture tutte o nessuna, sotto il lock dell'archivio:
     * prenotazioni, contatori. $work riceve questo stesso oggetto.
     */
    public function atomic(callable $work): mixed
    {
        if (self::$open !== null) return $work($this);
        $result = null;
        try {
            Stores::site(Plugins::root())->transaction(['content'], function (array $docs) use ($work, &$result): ?array {
                self::$open = $docs['content'];
                $before = self::$open;
                $result = $work($this);
                return self::$open === $before ? null : ['content' => self::$open];
            });
        } finally {
            self::$open = null;
            Site::forget();
        }
        return $result;
    }

    private function content(): array
    {
        return self::$open ?? Site::load(Plugins::root())['content'];
    }

    private function write(callable $change): void
    {
        if (self::$open !== null) {
            self::$open = $change(self::$open);
            return;
        }
        $this->atomic(function () use ($change): void {
            self::$open = $change(self::$open);
        });
    }

    private static function out(array $item): array
    {
        return ['id' => (int) $item['id'], 'hidden' => !empty($item['hidden']), 'values' => is_array($item['values'] ?? null) ? $item['values'] : []];
    }
}
