<?php
declare(strict_types=1);

namespace Kris\Update;

use Kris\Entity\JsonStore;
use Kris\Entity\StorageException;

/**
 * Versione del framework installato e stato del sito rispetto agli aggiornamenti.
 *
 * La versione del framework sta in kris/VERSION e arriva con il pacchetto.
 * Tutto il resto sta in data/kris_state.json, che appartiene al sito e
 * sopravvive agli aggiornamenti:
 *
 *   framework_version  versione che ha scritto lo stato l'ultima volta
 *   data_version       formato dei contenuti, avanza con le migrazioni
 *   migrations         nomi delle migrazioni gia applicate
 *   last_check         {at, latest, error} dell'ultimo "Verifica aggiornamenti"
 *   history            registro degli aggiornamenti: {at, user, from, to, outcome, message}
 */
final class SiteState
{
    public const FILE = '/data/kris_state.json';

    private const DEFAULTS = [
        'framework_version' => null,
        'data_version'      => null,
        'migrations'        => [],
        'last_check'        => null,
        'history'           => [],
    ];

    /** Versione del framework installato, letta da kris/VERSION. */
    public static function frameworkVersion(): string
    {
        $raw = @file_get_contents(KRIS_DIR . '/VERSION');
        $version = $raw === false ? '' : trim($raw);
        return $version !== '' ? $version : 'sconosciuta';
    }

    /**
     * Stato del sito, con i valori di default per i campi assenti.
     * Un sito che non ha ancora il file ottiene lo stato vuoto.
     *
     * @param string|null $file percorso alternativo, per i test; di default data/kris_state.json
     * @throws StorageException se il file esiste ma non e leggibile
     */
    public static function load(?string $file = null): array
    {
        $state = (new JsonStore($file ?? KRIS_ROOT . self::FILE))->read([]);
        return array_replace(self::DEFAULTS, $state);
    }

    /** @throws StorageException se non riesce a scrivere */
    public static function save(array $state, ?string $file = null): void
    {
        (new JsonStore($file ?? KRIS_ROOT . self::FILE))->write($state);
    }

    /** Aggiunge una voce al registro degli aggiornamenti (restano le ultime 50). */
    public static function addHistory(array $state, array $entry): array
    {
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $history[] = $entry + ['at' => date('c')];
        $state['history'] = array_slice($history, -50);
        return $state;
    }

    /** Ultima voce del registro degli aggiornamenti, o null se non ce ne sono. */
    public static function lastUpdate(array $state): ?array
    {
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $last = end($history);
        return is_array($last) ? $last : null;
    }
}
