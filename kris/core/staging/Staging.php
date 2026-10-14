<?php
declare(strict_types=1);

namespace Kris\Staging;

use Kris\Entity\JsonStore;

/**
 * Lo staging del sito: modifiche ai file del tema preparate (per esempio
 * dall'agente) e non ancora pubbliche.
 *
 * Vive in data/staging/, gia protetta come tutto data/, e ricalca la
 * struttura del sito: data/staging/template/home.html prende il posto di
 * template/home.html nell'anteprima (vedi Kris\Site\Paths). Contiene solo i
 * file modificati. manifest.json ricorda, per ogni file, l'impronta della
 * versione del sito da cui la modifica e partita: alla promozione, se il sito
 * nel frattempo e cambiato, il file e in conflitto.
 *
 * La promozione copia i file nel sito dopo averne salvato la versione
 * precedente in data/staging-backups/: l'ultima promozione si puo annullare
 * per BACKUP_DAYS giorni.
 */
final class Staging
{
    public const BACKUP_DAYS = 14;

    private string $root;
    private string $dir;
    private string $backups;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? KRIS_ROOT, '/\\');
        $this->dir = $this->root . '/data/staging';
        $this->backups = $this->root . '/data/staging-backups';
    }

    /** La cartella da sovrapporre al sito nell'anteprima. */
    public function dir(): string
    {
        return $this->dir;
    }

    public function exists(): bool
    {
        return is_file($this->dir . '/manifest.json');
    }

    /** @return array{created_at: string, created_by: string, files: array<string, array{base: ?string, changed_at: string, changed_by: string}>} */
    public function manifest(): array
    {
        $manifest = $this->exists() ? (new JsonStore($this->dir . '/manifest.json'))->read([]) : [];
        return [
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'created_by' => (string) ($manifest['created_by'] ?? ''),
            'files' => is_array($manifest['files'] ?? null) ? $manifest['files'] : [],
        ];
    }

    /**
     * Un file come lo vede l'anteprima: dallo staging se modificato, altrimenti dal sito.
     *
     * @return array{content: ?string, hash: ?string, source: string} content null = il file non esiste
     * @throws StagingException
     */
    public function read(string $relative): array
    {
        FilePolicy::assertEditable($relative);
        foreach (['staging' => $this->dir, 'site' => $this->root] as $source => $base) {
            if (is_file("{$base}/{$relative}")) {
                $content = (string) file_get_contents("{$base}/{$relative}");
                return ['content' => $content, 'hash' => self::hash($content), 'source' => $source];
            }
        }
        return ['content' => null, 'hash' => null, 'source' => 'none'];
    }

    /**
     * Scrive un file nello staging. $expectedHash e l'impronta della versione
     * letta da chi scrive (null per un file nuovo): se nel frattempo e cambiata,
     * la scrittura viene rifiutata invece di cancellare il lavoro di un altro.
     *
     * @return array{hash: string}
     * @throws StagingException
     */
    public function write(string $relative, string $content, ?string $expectedHash, string $by): array
    {
        FilePolicy::assertEditable($relative);
        FilePolicy::assertContent($relative, $content);
        $current = $this->read($relative);
        if ($current['hash'] !== $expectedHash) {
            throw new StagingException($current['hash'] === null
                ? "Il file '{$relative}' non esiste: rileggilo prima di modificarlo."
                : "Il file '{$relative}' è cambiato da quando l'hai letto: rileggilo e riapplica la modifica.");
        }

        $manifest = $this->manifest();
        $now = gmdate('c');
        if (!$this->exists()) {
            $manifest['created_at'] = $now;
            $manifest['created_by'] = $by;
        }
        if (!isset($manifest['files'][$relative])) {
            // La base e la versione del sito da cui parte la modifica, non quella dello staging
            $manifest['files'][$relative] = ['base' => self::hashOf("{$this->root}/{$relative}")];
        }
        $manifest['files'][$relative]['changed_at'] = $now;
        $manifest['files'][$relative]['changed_by'] = $by;

        self::writeFile("{$this->dir}/{$relative}", $content);
        (new JsonStore($this->dir . '/manifest.json'))->write($manifest);
        return ['hash' => self::hash($content)];
    }

    /**
     * Sostituisce un pezzo di testo che deve comparire una volta sola.
     *
     * @return array{hash: string}
     * @throws StagingException
     */
    public function edit(string $relative, string $old, string $new, string $expectedHash, string $by): array
    {
        $current = $this->read($relative);
        if ($current['content'] === null) {
            throw new StagingException("Il file '{$relative}' non esiste.");
        }
        if ($old === '') {
            throw new StagingException('Il testo da sostituire è vuoto.');
        }
        $count = substr_count($current['content'], $old);
        if ($count !== 1) {
            throw new StagingException($count === 0
                ? "Il testo da sostituire non compare in '{$relative}'."
                : "Il testo da sostituire compare {$count} volte in '{$relative}': includi più contesto perché sia unico.");
        }
        return $this->write($relative, str_replace($old, $new, $current['content']), $expectedHash, $by);
    }

    /**
     * Le modifiche in attesa, con quanto serve al riepilogo: versioni del sito
     * e dello staging, conflitti, elementi sensibili introdotti.
     *
     * @return list<array{file: string, kind: string, site: ?string, staged: string, conflict: bool, sensitive: string[], changed_at: string, changed_by: string}>
     */
    public function changes(): array
    {
        $out = [];
        foreach ($this->manifest()['files'] as $relative => $entry) {
            $stagedFile = "{$this->dir}/{$relative}";
            if (!is_file($stagedFile)) continue;
            $staged = (string) file_get_contents($stagedFile);
            $siteFile = "{$this->root}/{$relative}";
            $site = is_file($siteFile) ? (string) file_get_contents($siteFile) : null;
            $base = $entry['base'] ?? null;
            $out[] = [
                'file' => (string) $relative,
                'kind' => $base === null ? 'new' : 'modified',
                'site' => $site,
                'staged' => $staged,
                // Il sito e cambiato da quando la modifica e partita (o il file nuovo ora esiste gia)
                'conflict' => ($site === null ? null : self::hash($site)) !== $base,
                'sensitive' => array_values(array_diff(FilePolicy::sensitive($staged), FilePolicy::sensitive($site ?? ''))),
                'changed_at' => (string) ($entry['changed_at'] ?? ''),
                'changed_by' => (string) ($entry['changed_by'] ?? ''),
            ];
        }
        usort($out, fn($x, $y) => strcmp($x['file'], $y['file']));
        return $out;
    }

    /** Elimina lo staging senza toccare il sito. */
    public function discard(): void
    {
        self::removeTree($this->dir);
    }

    /**
     * Pubblica le modifiche: salva le versioni attuali del sito, copia i file
     * dello staging al loro posto ed elimina lo staging. Se una scrittura
     * fallisce, il sito torna com'era.
     *
     * @return array{backup: string, files: string[]}
     * @throws StagingException staging vuoto, conflitti senza $force, errore di scrittura
     */
    public function promote(string $by, bool $force = false): array
    {
        $changes = $this->changes();
        if (!$changes) {
            throw new StagingException('Non ci sono modifiche da pubblicare.');
        }
        $conflicts = array_column(array_filter($changes, fn($c) => $c['conflict']), 'file');
        if ($conflicts && !$force) {
            throw new StagingException('Alcuni file sono cambiati sul sito dopo la preparazione delle modifiche.', $conflicts);
        }

        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $backup = "{$this->backups}/{$id}";
        $files = [];
        foreach ($changes as $change) {
            $relative = $change['file'];
            $files[$relative] = ['existed' => $change['site'] !== null];
            if ($change['site'] !== null) {
                self::writeFile("{$backup}/files/{$relative}", $change['site']);
            }
        }
        $meta = ['id' => $id, 'promoted_at' => gmdate('c'), 'promoted_by' => $by, 'files' => $files];
        (new JsonStore("{$backup}/meta.json"))->write($meta);

        try {
            foreach ($changes as $change) {
                self::writeFile("{$this->root}/{$change['file']}", $change['staged']);
            }
        } catch (StagingException $e) {
            $this->restore($meta);
            throw new StagingException('La pubblicazione non è riuscita e il sito è tornato com\'era: ' . $e->getMessage());
        }

        $this->discard();
        $this->pruneBackups();
        return ['backup' => $id, 'files' => array_keys($files)];
    }

    /** L'ultima promozione che si puo ancora annullare, o null. */
    public function lastPromotion(): ?array
    {
        $this->pruneBackups();
        $metas = glob($this->backups . '/*/meta.json') ?: [];
        rsort($metas);
        foreach ($metas as $file) {
            $meta = (new JsonStore($file))->read([]);
            if (is_array($meta['files'] ?? null)) return $meta;
        }
        return null;
    }

    /**
     * Rimette i file dell'ultima promozione com'erano prima. I file che la
     * promozione aveva creato vengono tolti.
     *
     * @return string[] i file ripristinati
     * @throws StagingException nessuna promozione da annullare
     */
    public function undoLastPromotion(): array
    {
        $meta = $this->lastPromotion();
        if ($meta === null) {
            throw new StagingException('Non c\'è una pubblicazione da annullare.');
        }
        $this->restore($meta);
        self::removeTree("{$this->backups}/{$meta['id']}");
        return array_keys($meta['files']);
    }

    private function restore(array $meta): void
    {
        foreach ($meta['files'] as $relative => $info) {
            FilePolicy::assertEditable((string) $relative);
            $target = "{$this->root}/{$relative}";
            if (!empty($info['existed'])) {
                self::writeFile($target, (string) file_get_contents("{$this->backups}/{$meta['id']}/files/{$relative}"));
            } elseif (is_file($target)) {
                @unlink($target);
            }
        }
    }

    private function pruneBackups(): void
    {
        $limit = time() - self::BACKUP_DAYS * 86400;
        foreach (glob($this->backups . '/*/meta.json') ?: [] as $file) {
            if (filemtime($file) < $limit) self::removeTree(dirname($file));
        }
    }

    public static function hash(string $content): string
    {
        return hash('sha256', $content);
    }

    private static function hashOf(string $file): ?string
    {
        return is_file($file) ? self::hash((string) file_get_contents($file)) : null;
    }

    /** Scrittura atomica: file temporaneo nella stessa cartella, poi rename. */
    private static function writeFile(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new StagingException("Non riesco a creare la cartella di {$file}: controlla i permessi di scrittura.");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StagingException("Non riesco a scrivere {$file}: controlla i permessi di scrittura.");
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            is_dir("{$path}/{$item}") ? self::removeTree("{$path}/{$item}") : @unlink("{$path}/{$item}");
        }
        @rmdir($path);
    }
}
