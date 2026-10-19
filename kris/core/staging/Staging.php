<?php
declare(strict_types=1);

namespace Kris\Staging;

use Kris\Entity\JsonStore;
use Kris\Entity\MediaStore;
use Kris\Entity\StorageException;

/**
 * Lo staging del sito: modifiche preparate (per esempio dall'agente) e non
 * ancora pubbliche. Vive in data/staging/, gia protetta come tutto data/.
 *
 * Tre tipi di modifiche:
 *  - file del tema (vedi FilePolicy): copie modificate che ricalcano la
 *    struttura del sito, o file da eliminare ("deleted" nel manifest).
 *    manifest.json ricorda l'impronta della versione del sito da cui ogni
 *    modifica e partita: se il sito nel frattempo e cambiato, il file e in
 *    conflitto. JavaScript e SVG nuovi girano nell'anteprima solo dopo
 *    l'approvazione dell'admin (approveCode);
 *  - contenuti, schema e pagine: un elenco di operazioni (operations.json,
 *    vedi Operations) rieseguite sui dati del sito alla pubblicazione, cosi
 *    le modifiche che il cliente fa intanto dall'editor non vanno perse;
 *  - immagini caricate: in data/staging/uploads/, spostate in assets/uploads/
 *    alla pubblicazione.
 *
 * L'anteprima legge data/staging/ sovrapposta al sito (Kris\Site\Paths): per
 * i contenuti ci sono copie di k_data, k_model e delle pagine con le
 * operazioni gia applicate, ricostruite da rebuild().
 *
 * La pubblicazione salva prima le versioni attuali in data/staging-backups/:
 * l'ultima si puo annullare per BACKUP_DAYS giorni.
 */
final class Staging
{
    public const BACKUP_DAYS = 14;

    private const STATE_FILES = [
        'data' => 'data/k_data.json',
        'model' => 'data/k_model.json',
        'pages' => 'config/allowed_pages.json',
        'seo' => 'config/seo.json',
        'settings' => 'data/cms_settings.json',
    ];

    /** Operazioni che cambiano pagine e sitemap, e quelle che cambiano cms_settings. */
    private const PAGE_OPS = ['register_page', 'unregister_page', 'remove_collection', 'rename_collection'];
    private const SETTINGS_OPS = ['set_post_collection', 'remove_collection', 'rename_collection', 'set_collection_info', 'move_collection'];

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

    /** @return array{created_at: string, created_by: string, files: array, uploads: array} */
    public function manifest(): array
    {
        $manifest = $this->exists() ? (new JsonStore($this->dir . '/manifest.json'))->read([]) : [];
        return [
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'created_by' => (string) ($manifest['created_by'] ?? ''),
            'files' => is_array($manifest['files'] ?? null) ? $manifest['files'] : [],
            'uploads' => is_array($manifest['uploads'] ?? null) ? $manifest['uploads'] : [],
        ];
    }

    /** Vero se c'e qualcosa da pubblicare. */
    public function hasChanges(): bool
    {
        return $this->changes() || $this->operations() || $this->manifest()['uploads'];
    }

    // --- file del tema -------------------------------------------------------

    /**
     * Un file come lo vede l'anteprima: dallo staging se modificato, altrimenti dal sito.
     *
     * @return array{content: ?string, hash: ?string, source: string} content null = il file non esiste
     * @throws StagingException
     */
    public function read(string $relative): array
    {
        FilePolicy::assertEditable($relative);
        if (!empty($this->manifest()['files'][$relative]['deleted'])) {
            return ['content' => null, 'hash' => null, 'source' => 'staging'];
        }
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

        $manifest = $this->startManifest($by);
        if (!isset($manifest['files'][$relative])) {
            // La base e la versione del sito da cui parte la modifica, non quella dello staging
            $manifest['files'][$relative] = ['base' => self::hashOf("{$this->root}/{$relative}")];
        }
        unset($manifest['files'][$relative]['deleted']);
        $manifest['files'][$relative]['changed_at'] = gmdate('c');
        $manifest['files'][$relative]['changed_by'] = $by;

        self::writeFile("{$this->dir}/{$relative}", $content);
        $this->saveManifest($manifest);
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
     * Elimina un file del tema alla pubblicazione. $expectedHash e l'impronta
     * della versione letta. Il template di una pagina pubblica non si elimina
     * finche la pagina e registrata (prima unregister_page).
     *
     * @throws StagingException
     */
    public function delete(string $relative, string $expectedHash, string $by): void
    {
        FilePolicy::assertEditable($relative);
        $current = $this->read($relative);
        if ($current['hash'] === null) {
            throw new StagingException("Il file '{$relative}' non esiste.");
        }
        if ($current['hash'] !== $expectedHash) {
            throw new StagingException("Il file '{$relative}' è cambiato da quando l'hai letto: rileggilo prima di eliminarlo.");
        }
        if (preg_match('#^template/(.+)\.html$#', $relative, $m) === 1 && in_array($m[1], $this->state()['pages'], true)) {
            throw new StagingException("template/{$m[1]}.html è il template della pagina pubblica «{$m[1]}»: toglila prima con unregister_page.");
        }
        $manifest = $this->startManifest($by);
        $siteHash = self::hashOf("{$this->root}/{$relative}");
        if ($siteHash === null) {
            // Un file nuovo mai pubblicato: basta dimenticarlo
            unset($manifest['files'][$relative]);
        } else {
            $manifest['files'][$relative] = ['base' => $manifest['files'][$relative]['base'] ?? $siteHash,
                'deleted' => true, 'changed_at' => gmdate('c'), 'changed_by' => $by];
        }
        @unlink("{$this->dir}/{$relative}");
        $this->saveManifest($manifest);
    }

    /** @return string[] i file del tema che lo staging elimina */
    public function deletedFiles(): array
    {
        return array_keys(array_filter($this->manifest()['files'], fn($e) => !empty($e['deleted'])));
    }

    /**
     * I file del tema in attesa, con quanto serve al riepilogo: versioni del
     * sito e dello staging, conflitti, elementi sensibili introdotti.
     *
     * @return list<array{file: string, kind: string, site: ?string, staged: string, conflict: bool, sensitive: string[], changed_at: string, changed_by: string}>
     */
    public function changes(): array
    {
        $out = [];
        foreach ($this->manifest()['files'] as $relative => $entry) {
            $stagedFile = "{$this->dir}/{$relative}";
            $deleted = !empty($entry['deleted']);
            if (!$deleted && !is_file($stagedFile)) continue;
            $staged = $deleted ? '' : (string) file_get_contents($stagedFile);
            $siteFile = "{$this->root}/{$relative}";
            $site = is_file($siteFile) ? (string) file_get_contents($siteFile) : null;
            $base = $entry['base'] ?? null;
            $out[] = [
                'file' => (string) $relative,
                'kind' => $deleted ? 'deleted' : ($base === null ? 'new' : 'modified'),
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

    // --- contenuti, schema e pagine -------------------------------------------

    /**
     * Registra un'operazione su contenuti, schema o pagine (vedi Operations),
     * dopo averla provata sull'anteprima.
     *
     * @return array l'operazione completata, per esempio con l'id assegnato a un elemento nuovo
     * @throws StagingException operazione non valida
     */
    public function operate(array $op, string $by): array
    {
        return $this->operateAll([$op], $by)[0];
    }

    /**
     * Piu operazioni insieme, come un salvataggio dell'editor che cambia piu
     * campi: o si registrano tutte o nessuna.
     *
     * @return list<array> le operazioni completate
     * @throws StagingException la prima operazione non valida; nulla e stato registrato
     */
    public function operateAll(array $ops, string $by): array
    {
        if (!$ops) return [];
        [$state] = $this->replay($this->operations(), false);
        $languages = $this->languages();
        $done = [];
        foreach ($ops as $op) {
            if (($op['op'] ?? null) === 'register_page' && !$this->templateExists((string) ($op['page'] ?? ''))) {
                throw new StagingException("Per la pagina «{$op['page']}» serve prima il template template/{$op['page']}.html.");
            }
            [$state, $completed] = Operations::apply($state, $op, $languages, [$this, 'uploadExists']);
            $done[] = $completed;
        }

        $manifest = $this->startManifest($by);
        $this->saveManifest($manifest);
        $list = $this->operations();
        foreach ($done as $completed) {
            $list = self::append($list, $completed + ['by' => $by, 'at' => gmdate('c')]);
        }
        (new JsonStore($this->dir . '/operations.json'))->write($list);
        // Lo stato e gia quello dell'anteprima: niente secondo replay (sui siti grandi raddoppiava i tempi)
        $this->writePreview($state, $list);
        return $done;
    }

    /**
     * Aggiunge un'operazione all'elenco. Chi salva piu volte lo stesso campo
     * (dall'editor succede a ogni salvataggio) non allunga l'elenco: la nuova
     * modifica sostituisce la precedente, che conserva il valore di partenza
     * per il controllo dei conflitti. Se il campo torna al valore di partenza,
     * la modifica sparisce.
     */
    private static function append(array $list, array $op): array
    {
        if (($op['op'] ?? '') !== 'set_field') {
            $list[] = $op;
            return $list;
        }
        // Solo fra le modifiche di campi in fondo all'elenco: dopo un'operazione che
        // cambia la forma dei dati (elementi aggiunti o spostati) l'ordine conta
        for ($i = count($list) - 1; $i >= 0 && ($list[$i]['op'] ?? '') === 'set_field'; $i--) {
            if (self::sameField($list[$i], $op)) {
                $op['before'] = $list[$i]['before'] ?? null;
                array_splice($list, $i, 1);
                break;
            }
        }
        if (!array_key_exists('before', $op) || $op['before'] !== ($op['value'] ?? null)) {
            $list[] = $op;
        }
        return $list;
    }

    private static function sameField(array $a, array $b): bool
    {
        foreach (['key', 'field', 'lang'] as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) return false;
        }
        return (int) ($a['id'] ?? 0) === (int) ($b['id'] ?? 0)
            && array_map('strval', (array) ($a['path'] ?? [])) === array_map('strval', (array) ($b['path'] ?? []));
    }

    /** Contenuti, schema e pagine come li vede l'anteprima: il sito con le operazioni applicate. */
    public function state(): array
    {
        return $this->replay($this->operations(), false)[0];
    }

    /** @return list<array> le operazioni registrate, nell'ordine */
    public function operations(): array
    {
        $file = $this->dir . '/operations.json';
        return is_file($file) ? array_values((new JsonStore($file))->read([])) : [];
    }

    /**
     * Ricostruisce le copie dei contenuti usate dall'anteprima: dati attuali
     * del sito con le operazioni applicate. Le operazioni che non si possono
     * piu applicare vengono saltate e restituite come conflitti.
     *
     * @return list<array{op: array, description: string, conflict: ?string}>
     */
    public function rebuild(): array
    {
        $ops = $this->operations();
        if (!$ops) {
            $this->writePreview([], []);
            return [];
        }
        [$state, $report] = $this->replay($ops, false);
        $this->writePreview($state, $ops);
        return $report;
    }

    /**
     * Le operazioni con il loro esito sui dati attuali del sito, senza
     * riscrivere i file dell'anteprima: per chi deve solo leggere lo stato.
     *
     * @return list<array{op: array, description: string, conflict: ?string}>
     */
    public function report(): array
    {
        return $this->stateAndReport()[1];
    }

    /**
     * Stato dell'anteprima ed esito delle operazioni con un solo replay.
     *
     * @return array{0: array, 1: list<array{op: array, description: string, conflict: ?string}>}
     */
    public function stateAndReport(): array
    {
        return $this->replay($this->operations(), false);
    }

    /** Scrive le copie dei contenuti lette dall'anteprima; senza operazioni le toglie. */
    private function writePreview(array $state, array $ops): void
    {
        foreach (self::STATE_FILES as $relative) {
            @unlink("{$this->dir}/{$relative}");
        }
        if (!$ops) return;
        self::writeJson("{$this->dir}/data/k_data.json", $state['data']);
        self::writeJson("{$this->dir}/data/k_model.json", $state['model']);
        if (self::touches($ops, self::PAGE_OPS)) {
            self::writeJson("{$this->dir}/config/allowed_pages.json", ['allowed_pages' => array_values($state['pages'])]);
            self::writeJson("{$this->dir}/config/seo.json", $state['seo']);
        }
        if (self::touches($ops, self::SETTINGS_OPS)) {
            self::writeJson("{$this->dir}/data/cms_settings.json", $state['settings']);
        }
    }

    /**
     * Template che usano ancora campi tolti o rinominati dalle operazioni:
     * dopo la pubblicazione mostrerebbero un vuoto.
     *
     * @return string[]
     */
    public function templateProblems(): array
    {
        $retired = [];
        $collections = [];
        foreach ($this->operations() as $op) {
            foreach (Operations::retiredNames($op) as $name) $retired[$name] = true;
            foreach (Operations::retiredCollections($op) as $name) $collections[$name] = true;
        }
        $deleted = [];
        foreach ($this->deletedFiles() as $relative) {
            if (preg_match('#^template/(.+)\.html$#', $relative, $m) === 1) $deleted[$m[1]] = true;
        }
        if (!$retired && !$collections && !$deleted) return [];

        $templates = [];
        foreach ([$this->root, $this->dir] as $base) {
            foreach (glob("{$base}/template/*.html") ?: [] as $file) {
                $templates['template/' . basename($file)] = (string) file_get_contents($file);
            }
        }
        foreach (array_keys($deleted) as $name) unset($templates["template/{$name}.html"]);
        $problems = [];
        foreach ($templates as $relative => $html) {
            foreach (array_keys($retired) as $name) {
                $q = preg_quote((string) $name, '/');
                if (preg_match('/\{\{\s*' . $q . '\s*\}\}|\{\{#(?:if|elif)\s+' . $q . '\b|k-array=["\']' . $q . '["\']/', $html) === 1) {
                    $problems[] = "{$relative} usa ancora «{$name}»";
                }
            }
            foreach (array_keys($collections) as $name) {
                $q = preg_quote((string) $name, '/');
                if (preg_match('/k-(?:component|array)=["\']' . $q . '["\']|[?&;]key=' . $q . '(?=["\'&#\s])/', $html) === 1) {
                    $problems[] = "{$relative} usa ancora la raccolta «{$name}»";
                }
            }
            foreach (array_keys($deleted) as $name) {
                $q = preg_quote((string) $name, '/');
                if (preg_match('/k-template=["\']' . $q . '["\']/', $html) === 1) {
                    $problems[] = "{$relative} usa ancora il template eliminato «{$name}»";
                }
            }
        }
        sort($problems);
        return $problems;
    }

    // --- script nuovi: approvazione dell'anteprima -------------------------------

    /**
     * I file in attesa che portano codice eseguibile nel browser: JavaScript,
     * SVG e template che aggiungono script, gestori di eventi o URL javascript:.
     *
     * @return string[]
     */
    public function codeFiles(): array
    {
        $files = [];
        foreach ($this->changes() as $change) {
            if ($change['kind'] === 'deleted') continue;
            if (FilePolicy::isCode($change['file']) || FilePolicy::hasCodeSignal($change['sensitive'])) {
                $files[] = $change['file'];
            }
        }
        return $files;
    }

    /** Impronta dei file con codice cosi come sono ora, o null se non ce ne sono. */
    public function codeFingerprint(): ?string
    {
        $parts = [];
        foreach ($this->codeFiles() as $relative) {
            $parts[] = $relative . ':' . self::hashOf("{$this->dir}/{$relative}");
        }
        return $parts ? self::hash(implode("\n", $parts)) : null;
    }

    /** Vero se non c'e codice nuovo, o se l'admin ha approvato proprio questa versione. */
    public function codeApproved(): bool
    {
        $fingerprint = $this->codeFingerprint();
        if ($fingerprint === null) return true;
        $file = "{$this->dir}/code_approval.json";
        $approval = is_file($file) ? (new JsonStore($file))->read([]) : [];
        return ($approval['fingerprint'] ?? null) === $fingerprint;
    }

    /**
     * L'admin si assume la responsabilita del codice in attesa: l'anteprima lo
     * esegue. $fingerprint e quello che l'admin ha visto; se nel frattempo il
     * codice e cambiato l'approvazione viene rifiutata.
     *
     * @throws StagingException
     */
    public function approveCode(string $fingerprint, string $by): void
    {
        $current = $this->codeFingerprint();
        if ($current === null || !hash_equals($current, $fingerprint)) {
            throw new StagingException('Gli script in attesa sono cambiati: ricontrollali prima di approvarli.');
        }
        (new JsonStore("{$this->dir}/code_approval.json"))->write(['fingerprint' => $current, 'by' => $by, 'at' => gmdate('c')]);
    }

    // --- immagini --------------------------------------------------------------

    /**
     * Carica un'immagine o un PDF nello staging, con gli stessi controlli
     * dell'upload dell'editor. Restituisce l'URL che avra dopo la
     * pubblicazione, da usare nei campi image.
     *
     * @throws StagingException
     */
    public function upload(string $path, string $originalName, string $by): string
    {
        try {
            $url = (new MediaStore($this->dir . '/uploads/', 'assets/uploads/'))->storeLocal($path, $originalName);
        } catch (StorageException $e) {
            throw new StagingException($e->getMessage());
        }
        $manifest = $this->startManifest($by);
        $manifest['uploads'][basename($url)] = ['original' => $originalName, 'by' => $by, 'at' => gmdate('c')];
        $this->saveManifest($manifest);
        return $url;
    }

    /** @return string[] i percorsi dei file caricati nello staging, piu recenti prima */
    public function uploadFiles(): array
    {
        $files = array_values(array_filter(
            array_map(fn($name) => "{$this->dir}/uploads/{$name}", array_keys($this->manifest()['uploads'])),
            'is_file'
        ));
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files;
    }

    /** Vero se il file e stato caricato nello staging e non esiste ancora nel sito. */
    public function isStagedUpload(string $url): bool
    {
        $name = basename($url);
        return preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $url) === 1
            && is_file("{$this->dir}/uploads/{$name}") && !is_file("{$this->root}/assets/uploads/{$name}");
    }

    /** Un URL assets/uploads/<nome> esiste nel sito o fra le immagini dello staging. */
    public function uploadExists(string $url): bool
    {
        $name = basename($url);
        return is_file("{$this->root}/assets/uploads/{$name}") || is_file("{$this->dir}/uploads/{$name}");
    }

    // --- pubblicazione ---------------------------------------------------------

    /** Elimina lo staging senza toccare il sito. */
    public function discard(): void
    {
        self::removeTree($this->dir);
    }

    /**
     * Pubblica tutto: salva le versioni attuali del sito, scrive file del tema,
     * contenuti, schema, pagine e immagini, poi elimina lo staging. Se una
     * scrittura fallisce, il sito torna com'era.
     *
     * Conflitti (file o valori cambiati sul sito nel frattempo, operazioni non
     * piu applicabili) e template che usano campi tolti fermano la
     * pubblicazione, salvo $force: allora le modifiche dello staging vincono e
     * le operazioni impossibili vengono saltate.
     *
     * @return array{backup: string, files: string[]}
     * @throws StagingException
     */
    public function promote(string $by, bool $force = false): array
    {
        if (!$this->hasChanges()) {
            throw new StagingException('Non ci sono modifiche da pubblicare.');
        }
        $changes = $this->changes();
        $ops = $this->operations();
        [, $report] = $this->replay($ops, false);
        $problems = array_merge(
            array_map(fn($c) => "{$c['file']}: cambiato anche sul sito", array_filter($changes, fn($c) => $c['conflict'])),
            array_values(array_filter(array_column($report, 'conflict'))),
            $this->templateProblems()
        );
        if ($problems && !$force) {
            throw new StagingException('Alcune modifiche non si possono pubblicare così come sono.', $problems);
        }

        // Cosa cambia nel sito: percorso relativo => contenuto nuovo
        $writes = [];
        $deletes = [];
        foreach ($changes as $change) {
            if ($change['kind'] === 'deleted') {
                $deletes[] = $change['file'];
            } else {
                $writes[$change['file']] = $change['staged'];
            }
        }
        if ($ops) {
            [$state] = $this->replay($ops, true);
            $writes[self::STATE_FILES['data']] = self::encode($state['data']);
            $writes[self::STATE_FILES['model']] = self::encode($state['model']);
            if (self::touches($ops, self::PAGE_OPS)) {
                $writes[self::STATE_FILES['pages']] = self::encode(['allowed_pages' => array_values($state['pages'])]);
                $writes[self::STATE_FILES['seo']] = self::encode($state['seo']);
            }
            if (self::touches($ops, self::SETTINGS_OPS)) {
                $writes[self::STATE_FILES['settings']] = self::encode($state['settings']);
            }
        }
        $uploads = array_keys($this->manifest()['uploads']);
        foreach ($uploads as $name) {
            if (is_file("{$this->root}/assets/uploads/{$name}")) {
                throw new StagingException("Esiste già un file assets/uploads/{$name}: rinomina il caricamento e riprova.");
            }
        }

        // Copia di sicurezza di quello che verra sostituito
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $backup = "{$this->backups}/{$id}";
        $files = [];
        foreach (array_merge(array_keys($writes), $deletes, array_map(fn($n) => "assets/uploads/{$n}", $uploads)) as $relative) {
            $target = "{$this->root}/{$relative}";
            $files[$relative] = ['existed' => is_file($target)];
            if (is_file($target)) self::writeFile("{$backup}/files/{$relative}", (string) file_get_contents($target));
        }
        $meta = ['id' => $id, 'promoted_at' => gmdate('c'), 'promoted_by' => $by, 'files' => $files];
        (new JsonStore("{$backup}/meta.json"))->write($meta);

        try {
            foreach ($writes as $relative => $content) {
                self::writeFile("{$this->root}/{$relative}", $content);
            }
            foreach ($deletes as $relative) {
                if (is_file("{$this->root}/{$relative}") && !@unlink("{$this->root}/{$relative}")) {
                    throw new StagingException("Non riesco a eliminare {$relative}: controlla i permessi di scrittura.");
                }
            }
            foreach ($uploads as $name) {
                if (!is_dir("{$this->root}/assets/uploads")) @mkdir("{$this->root}/assets/uploads", 0775, true);
                if (!@copy("{$this->dir}/uploads/{$name}", "{$this->root}/assets/uploads/{$name}")) {
                    throw new StagingException("Non riesco a copiare l'immagine {$name} in assets/uploads/.");
                }
            }
        } catch (StagingException $e) {
            $this->restore($meta);
            throw new StagingException('La pubblicazione non è riuscita e il sito è tornato com\'era: ' . $e->getMessage());
        }

        $this->discard();
        $this->pruneBackups();
        return ['backup' => $id, 'files' => array_keys($files)];
    }

    /** L'ultima pubblicazione che si puo ancora annullare, o null. */
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
     * Rimette i file dell'ultima pubblicazione com'erano prima. I file che la
     * pubblicazione aveva creato (template nuovi, immagini) vengono tolti.
     *
     * @return string[] i file ripristinati
     * @throws StagingException nessuna pubblicazione da annullare
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

    // --- interni ---------------------------------------------------------------

    /**
     * Riapplica le operazioni ai dati attuali del sito.
     *
     * @return array{0: array, 1: list<array{op: array, description: string, conflict: ?string}>}
     */
    private function replay(array $ops, bool $force): array
    {
        $state = $this->siteState();
        $languages = $this->languages();
        $report = [];
        foreach ($ops as $op) {
            $conflict = null;
            try {
                [$state] = Operations::apply($state, $op, $languages, [$this, 'uploadExists'], true, $force);
            } catch (StagingException $e) {
                $conflict = $e->getMessage();
            }
            $report[] = ['op' => $op, 'description' => Operations::describe($op), 'conflict' => $conflict];
        }
        return [$state, $report];
    }

    private function siteState(): array
    {
        $read = fn(string $relative, array $default): array => is_file("{$this->root}/{$relative}")
            ? (new JsonStore("{$this->root}/{$relative}"))->read($default) : $default;
        $pages = $read(self::STATE_FILES['pages'], ['allowed_pages' => ['homepage']]);
        return [
            'data' => $read(self::STATE_FILES['data'], []),
            'model' => $read(self::STATE_FILES['model'], []),
            'pages' => is_array($pages['allowed_pages'] ?? null) ? array_values($pages['allowed_pages']) : ['homepage'],
            'seo' => $read(self::STATE_FILES['seo'], []),
            'settings' => $read(self::STATE_FILES['settings'], []),
        ];
    }

    private static function touches(array $ops, array $types): bool
    {
        return (bool) array_filter($ops, fn($o) => in_array($o['op'] ?? '', $types, true));
    }

    /** Le lingue attive del sito, come le legge il sito pubblico. */
    private function languages(): array
    {
        $file = "{$this->root}/data/cms_settings.json";
        $settings = is_file($file) ? (new JsonStore($file))->read([]) : [];
        return !empty($settings['languages']) && is_array($settings['languages']) ? array_values($settings['languages']) : ['it', 'en'];
    }

    private function templateExists(string $page): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $page) === 1
            && (is_file("{$this->root}/template/{$page}.html") || is_file("{$this->dir}/template/{$page}.html"));
    }

    private function startManifest(string $by): array
    {
        $manifest = $this->manifest();
        if (!$this->exists()) {
            $manifest['created_at'] = gmdate('c');
            $manifest['created_by'] = $by;
        }
        return $manifest;
    }

    private function saveManifest(array $manifest): void
    {
        (new JsonStore($this->dir . '/manifest.json'))->write($manifest);
    }

    private function restore(array $meta): void
    {
        foreach ($meta['files'] as $relative => $info) {
            FilePolicy::assertRestorable((string) $relative);
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

    /** Stessa forma dei file scritti dall'editor. */
    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private static function writeJson(string $file, array $data): void
    {
        self::writeFile($file, self::encode($data));
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
