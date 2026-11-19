<?php
declare(strict_types=1);

namespace Kris\Update;


/**
 * Migrazioni del formato dei dati.
 *
 * Ogni migrazione e un file kris/migrations/NNNN_nome.php che restituisce:
 *
 *   ['version' => int, 'description' => string, 'auto' => bool,
 *    'up' => fn(array $files): array]
 *
 * Fino alla 0009 i dati sono nel formato 1: $files contiene 'k_data',
 * 'k_model' e 'cms_settings' (null se il sito non ha il file), e in sola
 * lettura 'allowed_pages', 'seo' (null se mancano) e 'templates' (nome =>
 * HTML). La 0009 restituisce il formato 2: da lì in poi $files contiene
 * 'schema', 'content' e 'settings'. Regole, perche una migrazione scritta
 * oggi deve funzionare anche tra molte versioni:
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
    /** Chiave vista dalle migrazioni => documento dell'archivio, nel formato 1 e nel formato 2. */
    private const LEGACY = ['k_data' => 'data', 'k_model' => 'model', 'cms_settings' => 'cms_settings', 'allowed_pages' => 'pages', 'seo' => 'seo'];
    private const FORMAT2 = ['schema' => 'schema', 'content' => 'content', 'settings' => 'settings'];

    /** Le migrazioni fino a questa versione lavorano sui documenti della 1.x; la 0009 li converte nel formato 2. */
    private const FORMAT2_VERSION = 9;
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

    /**
     * Vero se ogni migrazione in sospeso si puo applicare senza chiedere: è
     * automatica, oppure sui dati di questo sito non cambierebbe niente (un
     * sito nuovo, già nel formato 2, non deve sentirsi chiedere la 0009).
     */
    public function pendingAreAutomatic(array $state): bool
    {
        $pending = $this->pending($state);
        $manual = array_filter($pending, fn($name) => !$this->all()[$name]['auto']);
        if (!$manual) return true;
        try {
            $store = \Kris\Store\Stores::site($this->root);
            $current = [];
            foreach (array_merge(self::LEGACY, self::FORMAT2) as $doc) $current[$doc] = $store->read($doc)->value;
            [$files, $format2] = $this->files($store, $current);
            foreach ($pending as $name) {
                if ($format2 && $this->all()[$name]['version'] < self::FORMAT2_VERSION) continue;
                $before = $files;
                $files = ($this->all()[$name]['up'])($files);
                if (!$this->all()[$name]['auto'] && $files !== $before) return false;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }

    /**
     * I documenti come li vedono le migrazioni: quelli del formato 2 se il
     * sito li ha, altrimenti quelli della 1.x con i template in lettura.
     *
     * @return array{0: array, 1: bool} documenti e formato 2 sì o no
     */
    private function files(\Kris\Store\Store $store, array $current): array
    {
        // Un documento che non esiste: null, come un file che manca nella 1.x
        $exists = fn(string $doc): bool => $store->read($doc)->rev !== '';
        $format2 = $exists('schema') && $current['schema'] !== [];
        $files = [];
        foreach ($format2 ? self::FORMAT2 : self::LEGACY as $key => $doc) {
            $files[$key] = $exists($doc) || in_array($key, ['k_data', 'k_model', 'content'], true) ? $current[$doc] : null;
        }
        if (!$format2) {
            $files['templates'] = [];
            foreach (glob($this->root . '/template/*.html') ?: [] as $file) $files['templates'][basename($file, '.html')] = (string) file_get_contents($file);
        }
        return [$files, $format2];
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

        // Sotto il lock dell'archivio, tutto o niente: anche con il database
        $store = \Kris\Store\Stores::site($this->root);
        $applied = is_array($state['migrations'] ?? null) ? $state['migrations'] : [];
        $version = is_int($state['data_version'] ?? null) ? $state['data_version'] : 0;
        $docs = array_values(array_merge(self::LEGACY, self::FORMAT2));
        $converted = null;
        $store->transaction($docs, function (array $current) use ($pending, $store, &$applied, &$version, &$converted): array {
            $exists = fn(string $doc): bool => $store->read($doc)->rev !== '';
            [$files, $format2] = $this->files($store, $current);
            $original = $files;
            foreach ($pending as $name) {
                $def = $this->all()[$name];
                // Dati già nel formato 2 (un sito nuovo della 2.0): le migrazioni della 1.x non hanno niente da fare
                if (isset($files['schema']) && $def['version'] < self::FORMAT2_VERSION) {
                    $applied[] = $name;
                    $version = max($version, $def['version']);
                    continue;
                }
                $result = ($def['up'])($files);
                $valid = is_array($result) && (isset($result['schema'])
                    ? is_array($result['schema']) && is_array($result['content'] ?? null) && is_array($result['settings'] ?? null)
                    : is_array($result['k_data'] ?? null) && is_array($result['k_model'] ?? null));
                if (!$valid) {
                    throw new UpdateException("La migrazione {$name} ha prodotto dati non validi.");
                }
                $files = $result;
                $applied[] = $name;
                $version = $def['version'];
            }
            // Dal formato 1 al formato 2: i documenti vecchi si tolgono dall'archivio (ne resta una copia, vedi sotto)
            if (!$format2 && isset($files['schema'])) {
                $converted = [];
                foreach (self::LEGACY as $key => $doc) {
                    if ($exists($doc)) $converted[$doc] = $current[$doc];
                }
            }
            $changed = [];
            foreach (isset($files['schema']) ? self::FORMAT2 : self::LEGACY as $key => $doc) {
                if (!in_array($key, ['allowed_pages', 'seo'], true) && ($files[$key] ?? null) !== null && $files[$key] !== ($original[$key] ?? null)) $changed[$doc] = $files[$key];
            }
            return $changed;
        });
        if ($converted) {
            // Copia in data/format1/ (per chi la cerca, e per tornare indietro a mano), poi fuori dall'archivio:
            // un k_data.json rimasto accanto a content.json sarebbe il file sbagliato da modificare
            $dir = $this->root . '/data/format1';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            foreach ($converted as $doc => $value) {
                @file_put_contents($dir . '/' . basename(\Kris\Store\FileStore::relativePath($doc)), json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $store->remove(array_keys($converted));
        }
        $state['migrations'] = $applied;
        $state['data_version'] = $version;
        return $state;
    }
}
