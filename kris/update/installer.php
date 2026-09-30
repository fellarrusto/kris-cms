<?php
declare(strict_types=1);

/**
 * Stadio 2 dell'aggiornamento: installa un pacchetto gia verificato.
 *
 * Questo file arriva DENTRO il pacchetto nuovo e viene eseguito dallo
 * stadio 1 della versione installata. Cosi la logica di installazione puo
 * migliorare a ogni release senza dover correggere i siti a mano.
 *
 * Contratto con lo stadio 1 (non va cambiato, solo esteso):
 *   kris_update_install(array $ctx): array
 *   $ctx: root, package (cartella kris/ estratta), from, to, user,
 *         release (RELEASE.json), keep_days
 *   ritorno: ['ok' => bool, 'rolled_back' => bool, 'error' => ?string,
 *             'steps' => [['label' => string, 'ok' => bool], ...]]
 *
 * Regole interne: il codice sostituisce la cartella da cui e stato caricato
 * il framework, quindi fino allo scambio usa solo funzioni di questo file,
 * e dopo lo scambio le classi del framework nuovo (caricate per la prima
 * volta in questa richiesta). Va incluso una sola volta per richiesta.
 */

namespace Kris\Install {

    const MAINTENANCE_FILE = '/data/kris_maintenance.json';
    const STATE_FILE = '/data/kris_state.json';
    const SNAPSHOTS_DIR = '/data/snapshots';
    const UPDATES_DIR = '/data/updates';
    const KEEP_DAYS = 14;

    /** Installa il pacchetto. Non lancia eccezioni: l'esito e nel valore di ritorno. */
    function install(array $ctx): array
    {
        $root = rtrim(str_replace('\\', '/', $ctx['root']), '/');
        $package = rtrim(str_replace('\\', '/', $ctx['package']), '/');
        $from = (string) $ctx['from'];
        $to = (string) $ctx['to'];
        $user = (string) ($ctx['user'] ?? '');
        $release = is_array($ctx['release'] ?? null) ? $ctx['release'] : [];
        $keepDays = (int) ($ctx['keep_days'] ?? KEEP_DAYS);

        @set_time_limit(300);
        ignore_user_abort(true);

        $run = new Run();

        // 1. Controlli che non toccano niente.
        $error = preflight($root, $package, $release);
        if (!$run->step('Controllo dell\'hosting', $error)) {
            return $run->result();
        }
        $error = lint_tree($package);
        if (!$run->step('Controllo del codice nuovo', $error)) {
            return $run->result();
        }

        // 2. Snapshot di dati e configurazione. Il framework vecchio vi
        //    entra al momento dello scambio, spostato e non copiato.
        $id = date('Ymd-His') . '-' . preg_replace('/[^0-9A-Za-z.]/', '', $from) . '-' . preg_replace('/[^0-9A-Za-z.]/', '', $to);
        $snapshot = $root . SNAPSHOTS_DIR . '/' . $id;
        try {
            snapshot_create($root, $snapshot, [
                'id' => $id, 'from' => $from, 'to' => $to, 'user' => $user,
                'created_at' => date('c'), 'outcome' => 'pending',
            ]);
        } catch (\Throwable $e) {
            remove_tree($snapshot);
            $run->step('Copia di sicurezza', 'Non riesco a creare la copia di sicurezza: ' . $e->getMessage());
            return $run->result();
        }
        $run->step('Copia di sicurezza', null);

        // 3. Da qui il sito e in manutenzione. Se la richiesta muore (errore
        //    fatale, tempo scaduto) il guardiano rimette tutto com'era.
        maintenance_on($root, ['from' => $from, 'to' => $to, 'snapshot' => $id]);
        $guard = new Guard($root, $snapshot, $from, $to, $user);
        register_shutdown_function([$guard, 'onShutdown']);

        // 4. Scambio delle cartelle.
        $error = swap($root, $package, $snapshot);
        if ($error !== null) {
            $guard->done = true;
            maintenance_off($root);
            remove_tree($snapshot);
            $run->step('Sostituzione dei file', $error);
            return $run->result();
        }
        $guard->swapped = true;
        clear_caches();
        // Il bootstrap nuovo registra il proprio autoload: una versione che
        // aggiunge namespace deve poter caricare le sue classi gia da qui.
        require $root . '/kris/bootstrap.php';
        $run->step('Sostituzione dei file', null);

        // 5. Migrazioni e verifica, con il framework nuovo.
        try {
            $state = read_json($root . STATE_FILE) ?? [];
            $migrator = new \Kris\Update\Migrator($root, $root . '/kris/migrations');
            if ($migrator->dataIsNewer($state)) {
                throw new \RuntimeException('i contenuti sono in un formato più recente di questa versione');
            }
            $state = $migrator->run($state);
            $run->step('Aggiornamento dei contenuti', null);

            $smoke = smoke_test($root);
            if ($smoke !== null) {
                throw new \RuntimeException($smoke);
            }
            $run->step('Verifica del sito', null);

            // 6. Stato e registro. Anche qui un errore (disco pieno) annulla
            //    tutto: un sito aggiornato senza stato registrato non e coerente.
            $state['framework_version'] = $to;
            $state = add_history($state, [
                'from' => $from, 'to' => $to, 'user' => $user, 'outcome' => 'ok',
                'snapshot' => $id, 'message' => (string) ($release['changelog_it'] ?? ''),
            ]);
            write_json($root . STATE_FILE, $state);
            $meta = read_json($snapshot . '/meta.json') ?? [];
            $meta['outcome'] = 'ok';
            $meta['expires_at'] = date('c', time() + $keepDays * 86400);
            write_json($snapshot . '/meta.json', $meta);
        } catch (\Throwable $e) {
            $labels = [4 => 'Aggiornamento dei contenuti', 5 => 'Verifica del sito'];
            $run->step($labels[$run->count()] ?? 'Registrazione dell\'aggiornamento', $e->getMessage());
            $guard->recover('Annullato: ' . $e->getMessage());
            $run->rolledBack = true;
            return $run->result();
        }

        // 7. Fatto: si riapre il sito e si fa pulizia.
        $guard->done = true;
        maintenance_off($root);
        prune($root, $keepDays, $id);
        $run->step('Pulizia', null);
        $run->ok = true;
        return $run->result();
    }

    /**
     * Torna alla versione precedente usando lo snapshot di un aggiornamento
     * riuscito. Ripristina framework, contenuti e configurazione di quel
     * momento; lo stato attuale viene parcheggiato in un altro snapshot, cosi
     * anche questa operazione resta recuperabile dallo sviluppatore.
     */
    function rollback(array $ctx): array
    {
        $root = rtrim(str_replace('\\', '/', $ctx['root']), '/');
        $id = (string) $ctx['snapshot'];
        $user = (string) ($ctx['user'] ?? '');
        $snapshot = $root . SNAPSHOTS_DIR . '/' . $id;
        $meta = read_json($snapshot . '/meta.json');
        $current = trim((string) @file_get_contents($root . '/kris/VERSION'));

        if (!preg_match('/^[0-9A-Za-z.\-]+$/', $id) || $meta === null || !is_dir($snapshot . '/kris')) {
            return ['ok' => false, 'error' => 'La copia di sicurezza non è più disponibile.'];
        }
        if (($meta['outcome'] ?? '') !== 'ok' || ($meta['to'] ?? '') !== $current) {
            return ['ok' => false, 'error' => 'Questa copia di sicurezza non corrisponde alla versione installata.'];
        }

        @set_time_limit(120);
        ignore_user_abort(true);
        maintenance_on($root, ['from' => $current, 'to' => $meta['from'], 'snapshot' => $id, 'rollback' => true]);

        // Lo stato attuale (framework e dati) va in uno snapshot "parcheggiato".
        $parkedId = date('Ymd-His') . '-annullato-' . $current;
        $parked = $root . SNAPSHOTS_DIR . '/' . $parkedId;
        try {
            snapshot_create($root, $parked, [
                'id' => $parkedId, 'from' => $current, 'to' => $meta['from'], 'user' => $user,
                'created_at' => date('c'), 'outcome' => 'parked',
            ]);
        } catch (\Throwable $e) {
            remove_tree($parked);
            maintenance_off($root);
            return ['ok' => false, 'error' => 'Non riesco a mettere da parte lo stato attuale: nulla è stato modificato.'];
        }

        if (!@rename($root . '/kris', $parked . '/kris')) {
            remove_tree($parked);
            maintenance_off($root);
            return ['ok' => false, 'error' => 'L\'hosting non permette di spostare la cartella kris/: nulla è stato modificato.'];
        }
        if (!@rename($snapshot . '/kris', $root . '/kris')) {
            @rename($parked . '/kris', $root . '/kris');
            remove_tree($parked);
            maintenance_off($root);
            return ['ok' => false, 'error' => 'Non riesco a rimettere la versione precedente: nulla è stato modificato.'];
        }
        // Il framework e gia tornato indietro: da qui il sito riapre comunque.
        try {
            $stateNow = read_json($root . STATE_FILE) ?? [];
            restore_files($snapshot, $root);
            clear_caches();

            $state = keep_log(read_json($root . STATE_FILE) ?? [], $stateNow);
            $state = add_history($state, [
                'from' => $current, 'to' => (string) $meta['from'], 'user' => $user,
                'outcome' => 'manual_rollback', 'snapshot' => $parkedId,
                'message' => 'Ripristinata la versione precedente.',
            ]);
            write_json($root . STATE_FILE, $state);
            remove_tree($snapshot);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'La versione precedente è stata rimessa, ma il registro non è stato aggiornato: ' . $e->getMessage()];
        } finally {
            maintenance_off($root);
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Rimette in piedi un sito rimasto in manutenzione perche la richiesta
     * dell'aggiornamento e stata uccisa senza poter eseguire il guardiano.
     */
    function recover(array $ctx): array
    {
        $root = rtrim(str_replace('\\', '/', $ctx['root']), '/');
        $info = read_json($root . MAINTENANCE_FILE);
        if ($info === null) {
            return ['ok' => true, 'error' => null];
        }
        $id = (string) ($info['snapshot'] ?? '');
        $snapshot = $root . SNAPSHOTS_DIR . '/' . $id;
        $meta = read_json($snapshot . '/meta.json');
        // Un aggiornamento gia concluso non va annullato: manca solo la pulizia.
        if (($meta['outcome'] ?? '') === 'ok' && empty($info['rollback'])) {
            maintenance_off($root);
            return ['ok' => true, 'error' => null];
        }
        // Senza kris/ il sito non parte: si rimette l'ultima cartella salvata.
        if (!is_dir($root . '/kris')) {
            $candidates = glob($root . SNAPSHOTS_DIR . '/*/kris', GLOB_ONLYDIR) ?: [];
            sort($candidates);
            if ($candidates) {
                @rename((string) end($candidates), $root . '/kris');
            }
        }
        if ($id !== '' && preg_match('/^[0-9A-Za-z.\-]+$/', $id) && empty($info['rollback'])) {
            $guard = new Guard($root, $snapshot, (string) ($info['from'] ?? ''), (string) ($info['to'] ?? ''), (string) ($ctx['user'] ?? ''));
            $guard->swapped = is_dir($snapshot . '/kris');
            $guard->recover('Aggiornamento interrotto: ripristinato lo stato precedente.');
            return ['ok' => true, 'error' => null];
        }
        maintenance_off($root);
        return ['ok' => true, 'error' => null];
    }

    // --- passi -----------------------------------------------------------

    function preflight(string $root, string $package, array $release): ?string
    {
        $php = (string) ($release['requires']['php'] ?? '8.1');
        if (version_compare(PHP_VERSION, $php, '<')) {
            return "Serve PHP {$php} o superiore, l'hosting ha " . PHP_VERSION . '.';
        }
        foreach ((array) ($release['requires']['ext'] ?? []) as $ext) {
            if (is_string($ext) && !extension_loaded($ext)) {
                return "Manca l'estensione PHP {$ext}: chiedi all'hosting di attivarla.";
            }
        }
        if (!is_dir($package) || !is_file($package . '/bootstrap.php')) {
            return 'Il pacchetto preparato non è completo: ripeti la verifica degli aggiornamenti.';
        }
        if (!is_writable($root) || !is_writable($root . '/kris')) {
            return 'PHP non può modificare la cartella del sito o la cartella kris/: controlla i permessi con l\'hosting.';
        }

        // Prova reale: crea, sposta tra data/ e la root, rimuove.
        $name = '.kris-probe-' . bin2hex(random_bytes(4));
        $inData = $root . UPDATES_DIR . '/' . $name;
        $inRoot = $root . '/' . $name;
        $ok = @mkdir($inData, 0775, true)
            && @file_put_contents($inData . '/probe.txt', 'ok') !== false
            && @rename($inData, $inRoot)
            && @rename($inRoot, $inData);
        remove_tree($inRoot);
        remove_tree($inData);
        if (!$ok) {
            return 'L\'hosting non permette a PHP di spostare cartelle: l\'aggiornamento da qui non è possibile.';
        }

        $free = function_exists('disk_free_space') ? @disk_free_space($root) : false;
        if ($free !== false && $free < 50 * 1024 * 1024) {
            return 'Lo spazio libero sull\'hosting è quasi esaurito.';
        }
        return null;
    }

    /** Controllo di sintassi di ogni file PHP, senza eseguirlo. */
    function lint_tree(string $dir): ?string
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            try {
                token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE);
            } catch (\ParseError $e) {
                $name = str_replace('\\', '/', substr($file->getPathname(), strlen($dir)));
                return "Errore di sintassi nel pacchetto (kris{$name}, riga {$e->getLine()}).";
            }
        }
        return null;
    }

    function snapshot_create(string $root, string $snapshot, array $meta): void
    {
        foreach (['data', 'config'] as $dir) {
            if (!@mkdir($snapshot . '/' . $dir, 0775, true) && !is_dir($snapshot . '/' . $dir)) {
                throw new \RuntimeException('cartella non creabile');
            }
        }
        foreach (snapshot_sources($root) as [$source, $relative]) {
            if (!@copy($source, $snapshot . '/' . $relative)) {
                throw new \RuntimeException("copia non riuscita di {$relative}");
            }
        }
        write_json($snapshot . '/meta.json', $meta);
    }

    /** File del sito che uno snapshot conserva: i JSON di data/ e i file di config/. */
    function snapshot_sources(string $root): array
    {
        $list = [];
        foreach (glob($root . '/data/*.json') ?: [] as $file) {
            if (basename($file) !== basename(MAINTENANCE_FILE)) {
                $list[] = [$file, 'data/' . basename($file)];
            }
        }
        foreach (array_merge(glob($root . '/config/*') ?: [], glob($root . '/config/.htaccess') ?: []) as $file) {
            if (is_file($file)) {
                $list[] = [$file, 'config/' . basename($file)];
            }
        }
        return $list;
    }

    /** Riporta nel sito i file di data/ e config/ salvati nello snapshot. */
    function restore_files(string $snapshot, string $root): void
    {
        $saved = [];
        foreach (['data', 'config'] as $dir) {
            foreach (scandir($snapshot . '/' . $dir) ?: [] as $name) {
                $file = $snapshot . '/' . $dir . '/' . $name;
                if (!is_file($file)) {
                    continue;
                }
                $target = $root . '/' . $dir . '/' . basename($file);
                $tmp = $target . '.restore-' . bin2hex(random_bytes(3));
                if (@copy($file, $tmp)) {
                    @rename($tmp, $target) || @unlink($tmp);
                }
                $saved[$dir . '/' . basename($file)] = true;
            }
        }
        // Un JSON creato dopo lo snapshot (da una migrazione) non deve restare.
        foreach (glob($root . '/data/*.json') ?: [] as $file) {
            $name = basename($file);
            if ($name !== basename(MAINTENANCE_FILE) && !isset($saved['data/' . $name])) {
                @unlink($file);
            }
        }
    }

    /** Scambia kris/ con il pacchetto; il framework vecchio finisce nello snapshot. */
    function swap(string $root, string $package, string $snapshot): ?string
    {
        if (!@rename($root . '/kris', $snapshot . '/kris')) {
            return 'L\'hosting non permette di spostare la cartella kris/: nulla è stato modificato.';
        }
        if (!@rename($package, $root . '/kris')) {
            @rename($snapshot . '/kris', $root . '/kris');
            return 'Non riesco a mettere al suo posto la versione nuova: nulla è stato modificato.';
        }
        return null;
    }

    /**
     * Verifica che il sito funzioni con il framework nuovo e i contenuti
     * reali: dati leggibili, template presenti, pagine renderizzabili.
     */
    function smoke_test(string $root): ?string
    {
        $data = read_json($root . '/data/k_data.json');
        $model = read_json($root . '/data/k_model.json');
        if ($data === null || $model === null) {
            return 'i contenuti non sono leggibili dopo l\'aggiornamento';
        }
        $pages = read_json($root . '/config/allowed_pages.json')['allowed_pages'] ?? ['homepage'];
        $settings = read_json($root . '/data/cms_settings.json');
        $lang = (string) ($settings['languages'][0] ?? 'it');

        $rootEntities = [];
        foreach ($data as $entity) {
            if (is_array($entity) && isset($entity['name']) && !isset($rootEntities[$entity['name']])) {
                $rootEntities[$entity['name']] = (int) ($entity['id'] ?? 0);
            }
        }

        foreach ((array) $pages as $page) {
            if (!is_string($page) || !preg_match('/^[a-zA-Z0-9_-]+$/', $page)) {
                continue;
            }
            $template = $root . '/template/' . $page . '.html';
            if (!is_file($template)) {
                return "manca il template della pagina {$page}";
            }
            // Si renderizzano le pagine che hanno un'entita omonima: le altre
            // (per esempio i dettagli) dipendono da parametri dell'URL.
            if (!isset($rootEntities[$page])) {
                continue;
            }
            $level = ob_get_level();
            ob_start();
            try {
                $entity = \Kris\Entity\Entity::fromPath('k_data', $page, $rootEntities[$page], []);
                $html = (new \Kris\Template\TemplateEngine($lang))->render((string) file_get_contents($template), $entity);
            } catch (\Throwable $e) {
                return "la pagina {$page} non si genera più ({$e->getMessage()})";
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
            if (trim((string) $html) === '') {
                return "la pagina {$page} risulta vuota";
            }
        }
        return null;
    }

    /** Rimuove snapshot scaduti e preparazioni vecchie. */
    function prune(string $root, int $keepDays, ?string $keep = null): void
    {
        $limit = time() - $keepDays * 86400;
        foreach (glob($root . SNAPSHOTS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) === $keep) {
                continue;
            }
            $meta = read_json($dir . '/meta.json');
            $created = $meta !== null ? strtotime((string) ($meta['created_at'] ?? '')) : false;
            if (($created !== false ? $created : filemtime($dir)) < $limit) {
                remove_tree($dir);
            }
        }
        foreach (glob($root . UPDATES_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 86400) {
                remove_tree($dir);
            }
        }
    }

    // --- utilita ---------------------------------------------------------

    function maintenance_on(string $root, array $info): void
    {
        write_json($root . MAINTENANCE_FILE, $info + ['started_at' => time()]);
    }

    function maintenance_off(string $root): void
    {
        @unlink($root . MAINTENANCE_FILE);
    }

    function clear_caches(): void
    {
        clearstatcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Stato ripristinato da uno snapshot, ma con registro e ultimo controllo
     * di adesso: il ripristino riporta indietro versione e contenuti, non la
     * memoria di cio che e successo.
     */
    function keep_log(array $restored, array $current): array
    {
        foreach (['history', 'last_check'] as $key) {
            if (array_key_exists($key, $current)) {
                $restored[$key] = $current[$key];
            }
        }
        return $restored;
    }

    function add_history(array $state, array $entry): array
    {
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $history[] = $entry + ['at' => date('c')];
        $state['history'] = array_slice($history, -50);
        return $state;
    }

    function read_json(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /** Scrittura atomica: temporaneo e rename, come JsonStore. */
    function write_json(string $file, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('scrittura non riuscita di ' . basename($file));
        }
    }

    function remove_tree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                remove_tree($path . '/' . $item);
            }
        }
        @rmdir($path);
    }

    /** Elenco dei passi e dell'esito, nel formato del contratto. */
    final class Run
    {
        public bool $ok = false;
        public bool $rolledBack = false;
        private array $steps = [];
        private ?string $error = null;

        /** Registra un passo; restituisce false se e fallito. */
        public function step(string $label, ?string $error): bool
        {
            $this->steps[] = ['label' => $label, 'ok' => $error === null];
            if ($error !== null) {
                $this->error = $error;
            }
            return $error === null;
        }

        public function count(): int
        {
            return count($this->steps);
        }

        public function result(): array
        {
            return ['ok' => $this->ok, 'rolled_back' => $this->rolledBack, 'error' => $this->error, 'steps' => $this->steps];
        }
    }

    /**
     * Rimette il sito com'era se l'installazione si interrompe dopo lo
     * snapshot: per un errore gestito o perche la richiesta muore.
     */
    final class Guard
    {
        public bool $done = false;
        public bool $swapped = false;

        public function __construct(
            private string $root,
            private string $snapshot,
            private string $from,
            private string $to,
            private string $user
        ) {
        }

        public function onShutdown(): void
        {
            if ($this->done) {
                return;
            }
            $fatal = error_get_last();
            @set_time_limit(60);
            $this->recover('Interrotto' . ($fatal ? ': ' . $fatal['message'] : '') . '. Ripristinato lo stato precedente.');
        }

        public function recover(string $message): void
        {
            if ($this->done) {
                return;
            }
            $this->done = true;
            if ($this->swapped && is_dir($this->snapshot . '/kris')) {
                $discard = $this->root . UPDATES_DIR . '/scartato-' . bin2hex(random_bytes(4));
                @mkdir($discard, 0775, true);
                if (is_dir($this->root . '/kris')) {
                    @rename($this->root . '/kris', $discard . '/kris');
                }
                @rename($this->snapshot . '/kris', $this->root . '/kris');
                remove_tree($discard);
            }
            $stateNow = read_json($this->root . STATE_FILE) ?? [];
            if (is_dir($this->snapshot)) {
                restore_files($this->snapshot, $this->root);
            }
            clear_caches();
            try {
                $state = keep_log(read_json($this->root . STATE_FILE) ?? [], $stateNow);
                write_json($this->root . STATE_FILE, add_history($state, [
                    'from' => $this->from, 'to' => $this->to, 'user' => $this->user,
                    'outcome' => 'rollback', 'message' => $message,
                ]));
            } catch (\Throwable) {
                // il registro e informativo: il ripristino conta di piu
            }
            remove_tree($this->snapshot);
            maintenance_off($this->root);
        }
    }
}

namespace {
    /** Punto d'ingresso dello stadio 2: vedi il contratto in testa al file. */
    function kris_update_install(array $ctx): array
    {
        return \Kris\Install\install($ctx);
    }
}
