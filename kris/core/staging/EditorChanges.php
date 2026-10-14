<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Traduce quello che fa l'editor in modalita «modifiche in attesa» nelle
 * operazioni dello staging (vedi Operations): l'editor modifica i dati come
 * sempre, qui si ricava cosa e cambiato. Funzioni pure, senza file.
 */
final class EditorChanges
{
    /**
     * Le modifiche di campo fra due versioni dei dati di un'entita.
     * I campi con lingue cambiano una lingua per volta: le traduzioni non
     * toccate (anche di lingue disattivate) restano come sono.
     *
     * @param array $path percorso dell'entita, come nelle operazioni: ['servizi', 2]
     * @param array $schema campi dell'entita
     * @param array $before campi prima del salvataggio (lista di {name, type, value})
     * @param array $after campi dopo il salvataggio
     * @return list<array> operazioni set_field
     */
    public static function fieldOps(string $key, int $id, array $path, array $schema, array $before, array $after, array $languages): array
    {
        $old = self::byName($before);
        $new = self::byName($after);
        $base = ['op' => 'set_field', 'key' => $key, 'id' => $id] + ($path ? ['path' => array_values($path)] : []);
        $ops = [];
        foreach ($schema as $def) {
            $name = (string) ($def['name'] ?? '');
            $type = (string) ($def['type'] ?? '');
            if ($type === 'array' || !array_key_exists($name, $new)) continue;
            if ($type === 'plain') {
                $o = self::scalar($old[$name] ?? '');
                $n = self::scalar($new[$name]);
                if ($o !== $n) $ops[] = $base + ['field' => $name, 'value' => $n];
                continue;
            }
            $o = is_array($old[$name] ?? null) ? $old[$name] : [];
            $n = is_array($new[$name]) ? $new[$name] : [];
            foreach ($languages as $lang) {
                $before = self::scalar($o[$lang] ?? '');
                $value = self::scalar($n[$lang] ?? '');
                if ($before !== $value) $ops[] = $base + ['field' => $name, 'lang' => $lang, 'value' => $value];
            }
        }
        return $ops;
    }

    /**
     * Gli spostamenti che portano un elenco di id dall'ordine $before a $after,
     * uno per elemento fuori posto: [id, posizione di arrivo]. Gli id che non
     * compaiono in $after restano in fondo, come nel riordino dell'editor.
     *
     * @param int[] $before
     * @param int[] $after
     * @return list<array{0: int, 1: int}>
     */
    public static function moves(array $before, array $after): array
    {
        $current = array_values(array_map('intval', $before));
        $target = [];
        foreach ($after as $id) {
            $id = (int) $id;
            if (in_array($id, $current, true) && !in_array($id, $target, true)) $target[] = $id;
        }
        $moves = [];
        foreach ($target as $to => $id) {
            if (($current[$to] ?? null) === $id) continue;
            array_splice($current, (int) array_search($id, $current, true), 1);
            array_splice($current, $to, 0, [$id]);
            $moves[] = [$id, $to];
        }
        return $moves;
    }

    private static function byName(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (is_array($field) && isset($field['name'])) $out[(string) $field['name']] = $field['value'] ?? null;
        }
        return $out;
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
