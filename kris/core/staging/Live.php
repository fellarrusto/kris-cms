<?php
declare(strict_types=1);

namespace Kris\Staging;

use Kris\Store\Site;
use Kris\Store\Stores;

/**
 * Le stesse operazioni dello staging (Operations), applicate subito al sito
 * pubblico: è il salvataggio dell'editor in «Sito pubblico». Così le due
 * modalità dell'editor hanno una sola logica e gli stessi controlli.
 *
 * Tutto dentro una transazione dell'archivio: o si applicano tutte le
 * operazioni, o nessuna, e nessun'altra scrittura si infila in mezzo.
 */
final class Live
{
    /** @var list<string> */
    private static array $warnings = [];

    /**
     * @param list<array> $ops
     * @return list<array> le operazioni completate (con l'id assegnato ai contenuti nuovi)
     * @throws StagingException la prima operazione non valida; nulla è stato scritto
     */
    public static function apply(string $root, array $ops): array
    {
        self::$warnings = [];
        if (!$ops) return [];
        $root = rtrim($root, '/\\');
        $done = [];
        $warnings = new \ArrayObject();
        Stores::site($root)->transaction(['schema', 'content', 'settings'], function (array $docs) use ($root, $ops, &$done, $warnings): array {
            $state = [
                'schema' => $docs['schema'] ?: ['format' => 2, 'types' => [], 'pages' => []],
                'content' => $docs['content'],
                'settings' => $docs['settings'],
            ];
            $before = $state;
            $languages = is_array($state['settings']['languages'] ?? null) && $state['settings']['languages']
                ? array_values(array_map('strval', $state['settings']['languages'])) : ['it', 'en'];
            $uploadExists = fn(string $url): bool => is_file("{$root}/assets/uploads/" . basename($url));
            foreach (array_values($ops) as $n => $op) {
                if (!is_array($op)) throw new StagingException("L'operazione {$n} non è un oggetto con \"op\". Nulla è stato modificato.");
                if (($op['op'] ?? null) === 'register_page' && !self::templateExists($root, (string) ($op['page'] ?? ''))) {
                    throw new StagingException("Per la pagina «{$op['page']}» serve prima il template template/{$op['page']}.html.");
                }
                try {
                    [$state, $completed] = Operations::apply($state, $op, $languages, $uploadExists, false, false, $warnings);
                } catch (StagingException $e) {
                    if (count($ops) === 1) throw $e;
                    throw new StagingException("Operazione {$n} (" . ($op['op'] ?? '?') . '): ' . $e->getMessage() . ' Nulla è stato modificato.', $e->details());
                }
                $done[] = $completed;
            }
            // Si riscrivono solo i documenti cambiati
            return array_filter($state, fn($value, $doc) => $value !== $before[$doc], ARRAY_FILTER_USE_BOTH);
        });
        Site::forget();
        self::$warnings = array_values(array_unique($warnings->getArrayCopy()));
        return $done;
    }

    /** @return list<string> gli avvisi dell'ultima apply (lingue rimaste vuote) */
    public static function lastWarnings(): array
    {
        return self::$warnings;
    }

    private static function templateExists(string $root, string $page): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $page) === 1 && is_file("{$root}/template/{$page}.html");
    }
}
