<?php
declare(strict_types=1);

namespace Kris\Agent;

use Kris\Entity\JsonStore;
use Kris\Staging\Operations;
use Kris\Staging\Staging;
use Kris\Staging\StagingException;

/**
 * Riceve le richieste firmate di Kris Agent (kris-agent.php) e le esegue
 * sullo staging. Pubblicare resta all'admin del sito, dall'editor: nessuna
 * azione qui tocca i file pubblici.
 *
 * Una richiesta e valida solo se:
 *  - il sito e abbinato e l'agente e acceso;
 *  - sito e chiave sono quelli dell'abbinamento in corso;
 *  - l'ora e entro WINDOW secondi da quella del server;
 *  - il nonce non e gia stato visto (niente richieste ripetute);
 *  - la firma Ed25519 del Worker copre metodo, sito, ora, nonce e corpo.
 */
final class Server
{
    public const WINDOW = 300;
    /** Operazioni al massimo in un gruppo: oltre, la richiesta e troppo lunga da ripetere a ogni anteprima */
    private const MAX_BATCH = 100;
    private const MAX_BODY = 12 * 1024 * 1024; // un'immagine da 8 MB in base64, piu il resto

    private string $root;

    public function __construct(private AgentConfig $config, private Staging $staging, ?string $root = null)
    {
        $this->root = rtrim($root ?? KRIS_ROOT, '/\\');
    }

    /**
     * @param array<string, string> $headers intestazioni con nomi in minuscolo
     * @return array{status: int, body: array}
     */
    public function handle(string $method, array $headers, string $body, ?int $now = null): array
    {
        $now ??= time();
        try {
            if ($method !== 'POST') throw new AgentException('Usa POST.', 405);
            if (strlen($body) > self::MAX_BODY) throw new AgentException('Richiesta troppo grande.', 413);
            $config = $this->config->load();
            if ($config === null) throw new AgentException('Questo sito non è abbinato a Kris Agent.', 403);
            if (!function_exists('sodium_crypto_sign_verify_detached')) throw new AgentException('Sul sito manca l\'estensione sodium.', 500);
            $this->verify($config, $headers, $body, $now);
            if (empty($config['enabled'])) throw new AgentException('L\'admin del sito ha disattivato Kris Agent.', 403);

            $request = json_decode($body, true);
            if (!is_array($request) || !is_string($request['action'] ?? null)) throw new AgentException('Corpo non valido: serve un JSON con "action".', 400);
            return ['status' => 200, 'body' => ['ok' => true] + $this->dispatch($request)];
        } catch (AgentException $e) {
            return ['status' => $e->getCode() ?: 400, 'body' => ['ok' => false, 'error' => $e->getMessage()]];
        } catch (StagingException $e) {
            $details = $e->details();
            return ['status' => 422, 'body' => ['ok' => false, 'error' => $e->getMessage()] + ($details ? ['details' => $details] : [])];
        }
    }

    /** @throws AgentException */
    private function verify(array $config, array $headers, string $body, int $now): void
    {
        $site = $headers['x-kris-site'] ?? '';
        $key = $headers['x-kris-key'] ?? '';
        $timestamp = $headers['x-kris-timestamp'] ?? '';
        $nonce = $headers['x-kris-nonce'] ?? '';
        $signature = base64_decode($headers['x-kris-signature'] ?? '', true);

        if ($site !== $config['site_id'] || $key !== ($config['key_id'] ?? '')) {
            throw new AgentException('Richiesta per un altro abbinamento: abbina di nuovo il sito.', 401);
        }
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::WINDOW) {
            throw new AgentException('Richiesta scaduta o con l\'ora sbagliata.', 401);
        }
        if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            throw new AgentException('Nonce non valido.', 401);
        }
        $message = implode("\n", ['KRIS-AGENT-1', 'POST', $site, $timestamp, $nonce, hash('sha256', $body)]);
        $publicKey = base64_decode((string) $config['worker_public_key'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $publicKey === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new AgentException('Firma non valida.', 401);
        }
        // Il nonce si registra solo dopo la firma: chi non firma non puo riempire il registro
        $this->rememberNonce($nonce, $now);
    }

    /** @throws AgentException richiesta gia vista */
    private function rememberNonce(string $nonce, int $now): void
    {
        $store = new JsonStore($this->root . '/data/agent_nonces.json');
        $seen = $store->exists() ? $store->read([]) : [];
        $seen = array_filter($seen, fn($at) => is_int($at) && $now - $at <= 2 * self::WINDOW);
        if (isset($seen[$nonce])) {
            throw new AgentException('Richiesta già ricevuta.', 401);
        }
        $seen[$nonce] = $now;
        $store->write($seen);
    }

    /** @throws AgentException|StagingException */
    private function dispatch(array $r): array
    {
        $by = is_string($r['by'] ?? null) && $r['by'] !== '' ? mb_substr('agente: ' . $r['by'], 0, 120) : 'agente';
        $s = $this->staging;
        switch ($r['action']) {
            case 'ping':
                return ['kris_version' => \Kris\Update\SiteState::frameworkVersion(), 'staging' => $s->hasChanges()];

            case 'overview':
                return $this->overview();

            case 'read_file':
                return $this->readFile($s->read($this->string($r, 'file')), $r);

            case 'search_theme':
                return $this->searchTheme($this->string($r, 'query'));

            case 'write_file':
                return $s->write($this->string($r, 'file'), $this->string($r, 'content', true), $this->hashOrNull($r), $by);

            case 'edit_file':
                // Senza impronta basta che il testo da sostituire compaia una volta sola: non serve rileggere il file intero
                $file = $this->string($r, 'file');
                return $s->edit($file, $this->string($r, 'old', true), $this->string($r, 'new', true), (string) ($this->hashOrNull($r) ?? $s->read($file)['hash']), $by);

            case 'render_page':
                return $this->renderPage($r);

            case 'site_config':
                return $this->siteConfig();

            case 'delete_file':
                $s->delete($this->string($r, 'file'), (string) $this->hashOrNull($r), $by);
                return ['deleted' => true];

            case 'get_entity':
                return ['entity' => $this->entity($s->state()['data'], $this->string($r, 'key'), (int) ($r['id'] ?? 0), (array) ($r['path'] ?? []))];

            case 'operate':
                if (!is_array($r['operation'] ?? null)) throw new AgentException('Serve "operation", un oggetto con "op".', 400);
                $done = $s->operate($r['operation'], $by);
                return ['operation' => $done, 'description' => Operations::describe($done)] + $this->warningsOf($s);

            case 'operate_batch':
                $ops = $r['operations'] ?? null;
                if (!is_array($ops) || !array_is_list($ops) || !$ops) throw new AgentException('Serve "operations", una lista di operazioni.', 400);
                if (count($ops) > self::MAX_BATCH) throw new AgentException('Al massimo ' . self::MAX_BATCH . ' operazioni per volta.', 400);
                $done = $s->operateAll($ops, $by);
                return ['operations' => array_map(fn($o) => ['operation' => $o, 'description' => Operations::describe($o)], $done)] + $this->warningsOf($s);

            case 'delete_upload':
                $s->deleteUpload($this->string($r, 'url'), $by);
                return ['deleted' => true];

            case 'upload':
                $data = base64_decode($this->string($r, 'data', true), true);
                if ($data === false || $data === '') throw new AgentException('"data" deve essere il file in base64.', 400);
                $tmp = tempnam(sys_get_temp_dir(), 'kris-agent-');
                file_put_contents($tmp, $data);
                try {
                    // Un .woff2 e un font del tema (assets/fonts/), il resto un'immagine o un PDF (assets/uploads/)
                    $name = basename($this->string($r, 'name'));
                    return ['url' => str_ends_with(strtolower($name), '.woff2') ? $s->uploadFont($tmp, $name, $by) : $s->upload($tmp, $name, $by)];
                } finally {
                    @unlink($tmp);
                }

            case 'status':
                return $this->status();

            case 'discard':
                $s->discard();
                return ['discarded' => true];

            default:
                throw new AgentException("Azione sconosciuta: {$r['action']}.", 404);
        }
    }

    /** Righe lette al massimo per volta da read_file e risultati da search_theme. */
    private const MAX_LINES = 400;
    private const MAX_MATCHES = 200;

    /**
     * Un file del tema, intero o a pezzi: from_line e lines per un intervallo,
     * search per le sole righe che contengono un testo, hash_only per la sola
     * impronta (quella da passare a write_file o edit_file). L'impronta è
     * sempre quella del file intero.
     */
    private function readFile(array $file, array $r): array
    {
        if ($file['content'] === null) return $file;
        $lines = preg_split('/\r\n|\n|\r/', $file['content']) ?: [];
        $total = count($lines);
        $meta = ['hash' => $file['hash'], 'source' => $file['source'], 'total_lines' => $total, 'size' => strlen($file['content'])];
        if (!empty($r['hash_only'])) return $meta;
        if (is_string($r['search'] ?? null) && $r['search'] !== '') {
            $matches = [];
            foreach ($lines as $i => $line) {
                if (mb_stripos($line, $r['search']) !== false) {
                    $matches[] = ['line' => $i + 1, 'text' => mb_substr($line, 0, 500)];
                    if (count($matches) >= self::MAX_MATCHES) break;
                }
            }
            return $meta + ['matches' => $matches];
        }
        if (!isset($r['from_line']) && !isset($r['lines'])) return $file + ['total_lines' => $total];
        $from = max(1, (int) ($r['from_line'] ?? 1));
        $count = max(1, min(self::MAX_LINES, (int) ($r['lines'] ?? self::MAX_LINES)));
        $slice = array_slice($lines, $from - 1, $count);
        return $meta + ['from_line' => $from, 'to_line' => $from + count($slice) - 1, 'content' => implode("\n", $slice)];
    }

    /** Le righe dei file del tema (template, CSS, JS, SVG) che contengono un testo, con lo staging sopra il sito. */
    private function searchTheme(string $query): array
    {
        $deleted = array_flip($this->staging->deletedFiles());
        $files = [];
        foreach (['template/*.html', 'assets/css/*.css', 'assets/js/*.js', 'assets/images/*.svg'] as $pattern) {
            foreach ([$this->root, $this->staging->dir()] as $base) {
                foreach (glob("{$base}/{$pattern}") ?: [] as $path) {
                    $relative = dirname($pattern) . '/' . basename($path);
                    if (!isset($deleted[$relative])) $files[$relative] = $path; // lo staging, letto dopo, vince
                }
            }
        }
        ksort($files);
        $matches = [];
        foreach ($files as $relative => $path) {
            foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [] as $i => $line) {
                if (mb_stripos($line, $query) === false) continue;
                $matches[] = ['file' => $relative, 'line' => $i + 1, 'text' => mb_substr($line, 0, 300)];
                if (count($matches) >= self::MAX_MATCHES) return ['matches' => $matches, 'truncated' => true];
            }
        }
        return ['matches' => $matches, 'truncated' => false];
    }

    /**
     * L'HTML finito di una pagina con le modifiche in attesa, compreso quanto
     * Kris aggiunge da solo (canonical, hreflang, favicon, versione dei file).
     * Senza la barra e il popup dell'anteprima.
     */
    private function renderPage(array $r): array
    {
        $query = [];
        foreach (['page', 'key', 'path', 'ln'] as $k) {
            if (is_string($r[$k] ?? null) && $r[$k] !== '') $query[$k] = $r[$k];
        }
        if (isset($r['id'])) $query['id'] = (string) (int) $r['id'];
        if ($this->staging->exists()) {
            $this->staging->rebuild();
            \Kris\Site\Paths::useOverlay($this->staging->dir(), $this->staging->deletedFiles());
        }
        try {
            $page = \Kris\Site\PageRenderer::render($query, $_SERVER);
            // Con gli indirizzi leggibili i link escono come li vedono i visitatori
            $routes = \Kris\Site\PageRenderer::routes();
            if ($routes !== null) {
                $page['html'] = \Kris\Site\Router::rewriteLinks($page['html'], $routes, \Kris\Site\Router::basePath($_SERVER), \Kris\Site\PageRenderer::language([])[1][0]);
            }
        } finally {
            \Kris\Site\Paths::useOverlay(null);
        }
        return ['status' => $page['status'], 'lang' => $page['lang'], 'html' => $page['html']];
    }

    /** Configurazione pubblica del sito, in sola lettura: pagine, seo.json e sitemap con le modifiche in attesa, robots.txt. */
    private function siteConfig(): array
    {
        $state = $this->staging->state();
        $settings = \Kris\Store\Stores::site($this->root)->read('settings')->value;
        $langs = !empty($settings['languages']) && is_array($settings['languages']) ? array_values($settings['languages']) : ['it', 'en'];
        $seo = \Kris\Template\Seo::config($this->root);
        $sitemap = is_array($state['seo']['sitemap'] ?? null) ? $state['seo']['sitemap'] : $seo['sitemap'];
        $robots = $this->root . '/robots.txt';
        return [
            'pages' => $state['pages'],
            'seo' => $state['seo'] ?: (object) [],
            'robots_txt' => is_file($robots) ? (string) file_get_contents($robots) : null,
            'sitemap_xml' => \Kris\Template\Seo::sitemapXml(
                \Kris\Template\Seo::sitemapEntries($state['data'], $sitemap, $state['pages']),
                \Kris\Template\Seo::baseUrl($seo, $_SERVER),
                $langs
            ),
        ];
    }

    /** Quello che serve all'agente per orientarsi: lingue, pagine, schema, contenuti, file del tema. */
    private function overview(): array
    {
        // Un solo replay delle operazioni per stato e riepilogo: sui siti grandi ogni replay costa
        [$state, $report] = $this->staging->stateAndReport();
        $settings = \Kris\Store\Stores::site($this->root)->read('settings')->value;
        $languages = !empty($settings['languages']) ? array_values($settings['languages']) : ['it', 'en'];

        $entities = [];
        foreach ($state['data'] as $entity) {
            $entities[$entity['name']][] = ['id' => $entity['id'], 'title' => $this->title($entity, $languages[0])] + (!empty($entity['hidden']) ? ['hidden' => true] : []);
        }
        $deleted = array_map('basename', $this->staging->deletedFiles());
        $files = fn(string $pattern): array => array_values(array_diff(array_unique(array_map(
            'basename',
            array_merge(glob("{$this->root}/{$pattern}") ?: [], glob($this->staging->dir() . "/{$pattern}") ?: [])
        )), $deleted));
        return [
            'languages' => $languages,
            'pages' => $state['pages'],
            'model' => $state['model'],
            // Nome visibile, descrizione, contenuto unico e ordine delle raccolte nell'editor
            'collections' => is_array($state['settings']['collections'] ?? null) ? $state['settings']['collections'] : (object) [],
            'collection_order' => is_array($state['settings']['collection_order'] ?? null) ? $state['settings']['collection_order'] : [],
            'entities' => $entities,
            'templates' => $files('template/*.html'),
            'stylesheets' => $files('assets/css/*.css'),
            'scripts' => $files('assets/js/*.js'),
            'svg_images' => $files('assets/images/*.svg'),
            'fonts' => $files('assets/fonts/*.woff2'),
            'staging' => $this->status($report, $state),
        ];
    }

    /** $state e $report: anteprima ed esito delle operazioni, se gia calcolati. Solo letture: i file dell'anteprima non si riscrivono. */
    private function status(?array $report = null, ?array $state = null): array
    {
        $s = $this->staging;
        // Un solo replay per esito e avvisi: sui siti grandi ogni replay costa
        if ($report === null) [$state, $report] = $s->stateAndReport();
        return [
            'has_changes' => $s->hasChanges(),
            'files' => array_map(fn($c) => ['file' => $c['file'], 'kind' => $c['kind'], 'conflict' => $c['conflict'], 'sensitive' => $c['sensitive']], $s->changes()),
            'operations' => array_map(fn($o) => ['description' => $o['description'], 'conflict' => $o['conflict']], $report),
            'uploads' => array_map(fn($n) => "assets/uploads/{$n}", array_keys($s->manifest()['uploads'])),
            'fonts' => array_map(fn($n) => "assets/fonts/{$n}", array_keys($s->manifest()['fonts'])),
            'template_problems' => $s->templateProblems(),
            // Da controllare, non bloccano la pubblicazione
            'warnings' => $s->warnings($state),
            // JavaScript e SVG nuovi girano nell'anteprima solo dopo l'approvazione dell'admin
            'code_awaiting_approval' => $s->codeApproved() ? [] : $s->codeFiles(),
            // L'anteprima si apre solo con il login dell'editor: la pubblicazione la decide l'admin
            'preview' => 'index.php?kris_preview=1',
            // Una per pagina toccata, anche quelle che nessun link del sito raggiunge
            'preview_pages' => $s->previewPages($state),
        ];
    }

    /** Gli avvisi dell'ultima registrazione, solo se ce ne sono. */
    private function warningsOf(Staging $s): array
    {
        return $s->lastWarnings() ? ['warnings' => $s->lastWarnings()] : [];
    }

    /** @throws AgentException */
    private function entity(array $data, string $key, int $id, array $path): array
    {
        foreach ($data as $entity) {
            if (($entity['name'] ?? null) === $key && (int) ($entity['id'] ?? -1) === $id) {
                $cur = $entity;
                for ($i = 0; $i + 1 < count($path); $i += 2) {
                    $next = null;
                    foreach ($cur['data'] ?? [] as $field) {
                        if (($field['name'] ?? null) === (string) $path[$i] && is_array($field['value'])) {
                            foreach ($field['value'] as $child) {
                                if ((int) ($child['id'] ?? -1) === (int) $path[$i + 1]) $next = $child;
                            }
                        }
                    }
                    if ($next === null) throw new AgentException("L'elemento {$path[$i]}/{$path[$i + 1]} non esiste.", 404);
                    $cur = $next;
                }
                return $cur;
            }
        }
        throw new AgentException("Il contenuto «{$key}» con id {$id} non esiste.", 404);
    }

    private function title(array $entity, string $lang): string
    {
        foreach ($entity['data'] ?? [] as $field) {
            if (in_array($field['type'] ?? '', ['text', 'plain'], true)) {
                $value = is_array($field['value']) ? ($field['value'][$lang] ?? reset($field['value'])) : $field['value'];
                if (is_string($value) && $value !== '') return mb_substr($value, 0, 80);
            }
        }
        return '';
    }

    /** @throws AgentException */
    private function string(array $r, string $key, bool $allowEmpty = false): string
    {
        if (!is_string($r[$key] ?? null) || (!$allowEmpty && $r[$key] === '')) {
            throw new AgentException("Manca \"{$key}\".", 400);
        }
        return $r[$key];
    }

    private function hashOrNull(array $r): ?string
    {
        return is_string($r['expected_hash'] ?? null) ? $r['expected_hash'] : null;
    }
}
