<?php
declare(strict_types=1);

namespace Kris\Update;

use Kris\Entity\JsonStore;

/**
 * Migrazioni del formato dei dati.
 *
 * Ogni migrazione e un file kris/migrations/NNNN_nome.php che restituisce:
 *
 *   ['version' => int, 'description' => string, 'auto' => bool,
 *    'up' => fn(array $files): array]
 *
 * $files contiene 'k_data', 'k_model' e 'cms_settings' (null se il sito non
 * ha il file). Regole, perche una migrazione scritta oggi deve funzionare
 * anche tra molte versioni:
 *  - solo in avanti: per tornare indietro si ripristina uno snapshot
 *  - funzione pura sugli array: niente file, niente rete
 *  - autosufficiente: non usa Entity, JsonRepository o altre classi del core
 *  - idempotente, e con un test fixture prima -> fixture dopo
 *
 * 'auto' => true e riservato alle migrazioni che non cambiano i contenuti:
 * l'editor le applica da solo, le altre le propone.
 */
final class Migrator
{
    private const FILES = [
        'k_data'       => ['/data/k_data.json', []],
        'k_model'      => ['/data/k_model.json', []],
        'cms_settings' => ['/data/cms_settings.json', null],
    ];

    private ?array $migrations = null;

    public function __construct(private string $root, private string $dir)
    {
    }

    /** @return array<string, array> migrazioni in ordine, indicizzate per nome */
    public function all(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }
        $list = [];
        $previous = 0;
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $name)) {
                continue;
            }
            $def = require $file;
            if (!is_array($def) || !is_int($def['version'] ?? null) || !is_callable($def['up'] ?? null)) {
                throw new UpdateException("La migrazione {$name} non è valida.");
            }
            if ($def['version'] <= $previous) {
                throw new UpdateException("Le migrazioni non sono in ordine di versione: {$name}.");
            }
            $previous = $def['version'];
            $list[$name] = $def + ['description' => '', 'auto' => false];
        }
        return $this->migrations = $list;
    }

    /** Versione dei dati che questo framework produce (quella dell'ultima migrazione). */
    public function latestDataVersion(): int
    {
        $all = $this->all();
        return $all ? (int) end($all)['version'] : 0;
    }

    /** Nomi delle migrazioni non ancora applicate, in ordine. */
    public function pending(array $state): array
    {
        $applied = array_flip(is_array($state['migrations'] ?? null) ? $state['migrations'] : []);
        return array_values(array_filter(array_keys($this->all()), fn($n) => !isset($applied[$n])));
    }

    /** Vero se ogni migrazione in sospeso si puo applicare senza chiedere. */
    public function pendingAreAutomatic(array $state): bool
    {
        foreach ($this->pending($state) as $name) {
            if (!$this->all()[$name]['auto']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Vero se i dati sono in un formato piu recente di quello che questo
     * framework conosce: tipicamente, un deploy via FTP da una copia vecchia.
     */
    public function dataIsNewer(array $state): bool
    {
        return is_int($state['data_version'] ?? null) && $state['data_version'] > $this->latestDataVersion();
    }

    /**
     * Applica le migrazioni in sospeso: tutte in memoria, poi si scrivono
     * solo i file cambiati. Restituisce lo stato aggiornato, che il chiamante
     * deve salvare.
     */
    public function run(array $state): array
    {
        $pending = $this->pending($state);
        if (!$pending) {
            return $state;
        }

        $stores = [];
        $files = [];
        foreach (self::FILES as $key => [$path, $default]) {
            $stores[$key] = new JsonStore($this->root . $path);
            $files[$key] = $stores[$key]->exists() ? $stores[$key]->read() : $default;
        }
        $original = $files;

        $applied = is_array($state['migrations'] ?? null) ? $state['migrations'] : [];
        $version = is_int($state['data_version'] ?? null) ? $state['data_version'] : 0;
        foreach ($pending as $name) {
            $def = $this->all()[$name];
            $result = ($def['up'])($files);
            if (!is_array($result) || !is_array($result['k_data'] ?? null) || !is_array($result['k_model'] ?? null)) {
                throw new UpdateException("La migrazione {$name} ha prodotto dati non validi.");
            }
            $files = $result;
            $applied[] = $name;
            $version = $def['version'];
        }

        foreach (self::FILES as $key => $_) {
            if ($files[$key] !== null && $files[$key] !== $original[$key]) {
                $stores[$key]->write($files[$key]);
            }
        }

        $state['migrations'] = $applied;
        $state['data_version'] = $version;
        return $state;
    }
}
