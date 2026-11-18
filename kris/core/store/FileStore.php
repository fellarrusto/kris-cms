<?php
declare(strict_types=1);

namespace Kris\Store;

use Kris\Entity\JsonStore;
use Kris\Site\Paths;

/**
 * Adapter JSON: un file per documento, come la 1.x. Scrittura atomica con
 * copia precedente (Kris\Entity\JsonStore); le scritture con revisione e le
 * transazioni passano da un lock esclusivo su data/.store.lock.
 *
 * $preview: le letture rispettano la sovrapposizione dell'anteprima
 * (Paths::useOverlay). Solo per il rendering: le scritture vanno sempre ai
 * file del sito.
 */
final class FileStore implements Store
{
    private const FILES = [
        'schema' => 'data/schema.json',
        'content' => 'data/content.json',
        'settings' => 'data/settings.json',
        // Formato 1: li legge la migrazione 0009
        'data' => 'data/k_data.json',
        'model' => 'data/k_model.json',
        'cms_settings' => 'data/cms_settings.json',
        'pages' => 'config/allowed_pages.json',
        'seo' => 'config/seo.json',
    ];

    /** @var resource|null */
    private $lock = null;
    private int $lockDepth = 0;

    public function __construct(private string $root, private bool $preview = false)
    {
        $this->root = rtrim($root, '/\\');
    }

    public function read(string $doc): Snapshot
    {
        [$value, $rev] = $this->readFile($this->readPath($doc));
        return new Snapshot($value, $rev);
    }

    public function write(string $doc, array $value, ?string $expectedRev = null): string
    {
        return $this->locked(function () use ($doc, $value, $expectedRev): string {
            $file = $this->sitePath($doc);
            if ($expectedRev !== null) {
                $current = $this->revOf($file);
                if (!hash_equals($current, $expectedRev)) throw new ConflictException($doc, $current);
            }
            (new JsonStore($file))->write($value);
            Site::forget();
            return $this->revOf($file);
        });
    }

    public function transaction(array $docs, callable $change): void
    {
        $this->locked(function () use ($docs, $change): void {
            $current = [];
            foreach ($docs as $doc) $current[$doc] = $this->readFile($this->sitePath($doc))[0];
            $changed = $change($current);
            if (!is_array($changed)) return;
            foreach (array_keys($changed) as $doc) {
                if (!in_array($doc, $docs, true)) {
                    throw new StoreException("La transazione non ha letto «{$doc}»: non può scriverlo.");
                }
            }
            foreach ($changed as $doc => $value) (new JsonStore($this->sitePath($doc)))->write($value);
            Site::forget();
        });
    }

    public function export(): array
    {
        $out = [];
        foreach (array_keys(self::FILES) as $doc) {
            $file = $this->sitePath($doc);
            if (is_file($file)) $out[$doc] = $this->readFile($file)[0];
        }
        return $out;
    }

    public function import(array $documents): void
    {
        $this->locked(function () use ($documents): void {
            $this->transaction(array_keys($documents), fn(): array => $documents);
            foreach (array_diff(array_keys(self::FILES), array_keys($documents)) as $doc) {
                if (is_file($this->sitePath($doc))) @unlink($this->sitePath($doc));
            }
            Site::forget();
        });
    }

    /** Il file di un documento, relativo alla root del sito: per snapshot, staging e messaggi. */
    public static function relativePath(string $doc): string
    {
        if (!isset(self::FILES[$doc])) throw new StoreException("Documento sconosciuto: «{$doc}».");
        return self::FILES[$doc];
    }

    /** @return array{0: array, 1: string} */
    private function readFile(string $file): array
    {
        if (!is_file($file)) return [[], ''];
        return [(new JsonStore($file))->read(), $this->revOf($file)];
    }

    private function revOf(string $file): string
    {
        if (!is_file($file)) return '';
        $raw = @file_get_contents($file);
        if ($raw === false) throw new StoreException("Impossibile leggere l'archivio: {$file}");
        return hash('sha256', $raw);
    }

    private function readPath(string $doc): string
    {
        $relative = self::relativePath($doc);
        // La sovrapposizione dell'anteprima vale solo per il sito di KRIS_ROOT
        if ($this->preview && $this->root === rtrim(KRIS_ROOT, '/\\')) return Paths::file($relative);
        return $this->root . '/' . $relative;
    }

    private function sitePath(string $doc): string
    {
        return $this->root . '/' . self::relativePath($doc);
    }

    /** Esegue $work con il lock esclusivo; rientrante nella stessa richiesta. */
    private function locked(callable $work): mixed
    {
        if ($this->lockDepth === 0) {
            $dir = $this->root . '/data';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new StoreException("Cartella dati non creabile: {$dir}");
            }
            $handle = @fopen($dir . '/.store.lock', 'c');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                throw new StoreException("Impossibile ottenere il lock dell'archivio.");
            }
            $this->lock = $handle;
        }
        $this->lockDepth++;
        try {
            return $work();
        } finally {
            if (--$this->lockDepth === 0 && $this->lock !== null) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
                $this->lock = null;
            }
        }
    }
}
