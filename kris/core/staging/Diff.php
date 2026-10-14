<?php
declare(strict_types=1);

namespace Kris\Staging;

/** Differenze riga per riga tra due versioni di un file, per il riepilogo nell'editor. */
final class Diff
{
    // Oltre questa dimensione (righe cambiate vecchie x nuove) il calcolo esatto costa troppa memoria
    private const MAX_CELLS = 250_000;

    /**
     * Righe con il loro esito: ' ' invariata, '-' tolta, '+' aggiunta. Le righe
     * invariate lontane dalle modifiche diventano un solo segnaposto '…'.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function lines(string $old, string $new, int $context = 3): array
    {
        $a = $old === '' ? [] : explode("\n", str_replace("\r\n", "\n", $old));
        $b = $new === '' ? [] : explode("\n", str_replace("\r\n", "\n", $new));

        // Le modifiche tipiche toccano poche righe: inizio e fine comuni si tolgono subito
        $prefix = 0;
        while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) $prefix++;
        $suffix = 0;
        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix
            && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) $suffix++;

        $head = array_map(fn($l) => [' ', $l], array_slice($a, 0, $prefix));
        $tail = array_map(fn($l) => [' ', $l], array_slice($a, count($a) - $suffix));
        $middle = self::middle(array_slice($a, $prefix, count($a) - $prefix - $suffix), array_slice($b, $prefix, count($b) - $prefix - $suffix));

        return self::collapse(array_merge($head, $middle, $tail), $context);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n * $m > self::MAX_CELLS) {
            return array_merge(array_map(fn($l) => ['-', $l], $a), array_map(fn($l) => ['+', $l], $b));
        }
        // Sottosequenza comune piu lunga, calcolata dal fondo
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['-', $a[$i++]];
            } else {
                $out[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) $out[] = ['-', $a[$i++]];
        while ($j < $m) $out[] = ['+', $b[$j++]];
        return $out;
    }

    /** @param list<array{0: string, 1: string}> $lines */
    private static function collapse(array $lines, int $context): array
    {
        $keep = [];
        foreach ($lines as $k => [$op]) {
            if ($op === ' ') continue;
            for ($x = max(0, $k - $context); $x <= min(count($lines) - 1, $k + $context); $x++) $keep[$x] = true;
        }
        $out = [];
        $skipping = false;
        foreach ($lines as $k => $line) {
            if (isset($keep[$k])) {
                $out[] = $line;
                $skipping = false;
            } elseif (!$skipping) {
                $out[] = ['…', ''];
                $skipping = true;
            }
        }
        return $out;
    }
}
