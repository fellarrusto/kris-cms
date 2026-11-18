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
     * Le modifiche di campo fra due versioni dei valori di un contenuto.
     * I campi con lingue cambiano una lingua per volta: le traduzioni non
     * toccate (anche di lingue disattivate) restano come sono.
     *
     * @param array $path percorso dell'elemento, come nelle operazioni: ['servizi', 2]
     * @param array $fields definizioni dei campi del contenuto (formato 2)
     * @param array $before valori per nome prima del salvataggio
     * @param array $after valori per nome dopo il salvataggio
     * @return list<array> operazioni set_field
     */
    public static function fieldOps(string $key, int $id, array $path, array $fields, array $before, array $after, array $languages): array
    {
        $base = ['op' => 'set_field', 'key' => $key, 'id' => $id] + ($path ? ['path' => array_values($path)] : []);
        $ops = [];
        foreach ($fields as $def) {
            $name = (string) ($def['name'] ?? '');
            if (($def['type'] ?? '') === 'list' || !array_key_exists($name, $after)) continue;
            if (empty($def['localized'])) {
                if (!self::same($before[$name] ?? null, $after[$name])) $ops[] = $base + ['field' => $name, 'value' => $after[$name]];
                continue;
            }
            $o = is_array($before[$name] ?? null) ? $before[$name] : [];
            $n = is_array($after[$name]) ? $after[$name] : [];
            foreach ($languages as $lang) {
                if (!array_key_exists($lang, $n)) continue;
                if (!self::same($o[$lang] ?? null, $n[$lang])) $ops[] = $base + ['field' => $name, 'lang' => $lang, 'value' => $n[$lang]];
            }
        }
        return $ops;
    }

    /** Stesso valore, con un valore mancante uguale a quello vuoto. */
    private static function same(mixed $a, mixed $b): bool
    {
        $empty = fn($v) => $v === null || $v === '' || $v === false || $v === [];
        return $a === $b || ($empty($a) && $empty($b));
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
}
