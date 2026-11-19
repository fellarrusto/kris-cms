<?php
declare(strict_types=1);

namespace Kris\Plugin;

use Kris\Store\Site;
use Throwable;

/**
 * I plugin installati e i loro agganci (docs/2.0/plugin.md, 4–5).
 *
 * - Stato in data/kris_plugins.json (installati, versione, acceso o no,
 *   migrazioni, ultimo errore): lo scrive solo il core.
 * - Il codice di un plugin (plugins/<nome>/plugin.php) si include solo nelle
 *   richieste che lo riguardano e solo se è acceso; ogni aggancio gira in un
 *   try: un'eccezione finisce nel registro del plugin e la pagina continua.
 *   Un errore fatale durante un aggancio spegne il plugin (funzione di shutdown).
 * - config/plugins.php: 'safe_mode' => true spegne tutti i plugin senza
 *   toccarne lo stato; 'enabled' => false toglie i plugin dall'editor.
 */
final class Plugins
{
    public const STATE = 'data/kris_plugins.json';
    public const LOGS = 'data/kris_plugin_logs';
    public const LOG_LINES = 200;
    public const MAX_BODY = 65536;
    private const RATE = 'data/kris_plugin_rate.json';
    private const KEY = 'config/plugin_key.php';
    private const FORM_TOKEN_TTL = 7200;

    /** @var array<string, Context|false> plugin avviati in questa richiesta (false: non si avvia) */
    private static array $contexts = [];
    private static ?array $installed = null;
    private static ?string $running = null;
    private static bool $suspended = false;
    private static bool $guarded = false;
    private static ?string $root = null;

    public static function root(): string
    {
        return self::$root ?? rtrim(str_replace('\\', '/', KRIS_ROOT), '/');
    }

    /** Solo per i test e gli strumenti: lavora su un altro sito. */
    public static function useRoot(?string $root): void
    {
        self::$root = $root === null ? null : rtrim(str_replace('\\', '/', $root), '/');
        self::reset();
    }

    /** config/plugins.php, facoltativo. */
    public static function config(): array
    {
        $file = self::root() . '/config/plugins.php';
        $config = is_file($file) ? require $file : [];
        return is_array($config) ? $config : [];
    }

    /** Modalità sicura: nessun plugin gira, lo stato resta com'è. */
    public static function safeMode(): bool
    {
        return (self::config()['safe_mode'] ?? false) === true;
    }

    /** Falso con 'enabled' => false: l'editor non offre i plugin. */
    public static function enabled(): bool
    {
        return (self::config()['enabled'] ?? true) !== false;
    }

    /** Nell'anteprima dello staging i plugin non girano (plugin.md, 5). */
    public static function suspend(bool $on = true): void
    {
        self::$suspended = $on;
    }

    /** Dopo un'installazione o nei test: rilegge stato e manifest. */
    public static function reset(): void
    {
        self::$contexts = [];
        self::$installed = null;
        self::$suspended = false;
    }

    // --- stato ------------------------------------------------------------

    /** @return array{plugins: array<string, array>} */
    public static function state(): array
    {
        $raw = @file_get_contents(self::root() . '/' . self::STATE);
        $state = $raw === false ? [] : json_decode($raw, true);
        $state = is_array($state) ? $state : [];
        $state['plugins'] = is_array($state['plugins'] ?? null) ? $state['plugins'] : [];
        return $state;
    }

    /** Cambia lo stato di un plugin sotto lock; $change riceve la voce (o null) e restituisce la nuova (null la toglie). */
    public static function updateState(string $name, callable $change): void
    {
        $file = self::root() . '/' . self::STATE;
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        $lock = @fopen($file . '.lock', 'c');
        if ($lock !== false) flock($lock, LOCK_EX);
        try {
            $state = self::state();
            $entry = $change($state['plugins'][$name] ?? null);
            if ($entry === null) unset($state['plugins'][$name]);
            else $state['plugins'][$name] = $entry;
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                throw new PluginException('Non riesco a scrivere data/kris_plugins.json: controlla i permessi di data/.');
            }
        } finally {
            if ($lock !== false) { flock($lock, LOCK_UN); fclose($lock); }
            self::$installed = null;
        }
    }

    /**
     * I plugin installati, nell'ordine di installazione: manifest e stato.
     *
     * @return array<string, array{manifest: array, state: array}>
     */
    public static function installed(): array
    {
        if (self::$installed !== null) return self::$installed;
        $out = [];
        $entries = self::state()['plugins'];
        uasort($entries, fn($a, $b) => strcmp((string) ($a['installed_at'] ?? ''), (string) ($b['installed_at'] ?? '')));
        foreach ($entries as $name => $entry) {
            if (!is_string($name) || preg_match(Manifest::NAME, $name) !== 1 || !is_array($entry)) continue;
            $manifest = Manifest::read(self::root() . '/plugins/' . $name);
            if ($manifest === null || $manifest['name'] !== $name) continue;
            $out[$name] = ['manifest' => $manifest, 'state' => $entry];
        }
        return self::$installed = $out;
    }

    /** I nomi dei plugin accesi, nell'ordine; nessuno in modalità sicura o nell'anteprima. */
    public static function active(): array
    {
        if (self::$suspended || self::safeMode()) return [];
        return array_keys(array_filter(self::installed(), fn($p) => ($p['state']['active'] ?? false) === true));
    }

    /** I plugin accesi che dichiarano l'aggancio: per non includere codice che non serve. */
    private static function withHook(string $hook): array
    {
        $installed = self::installed();
        return array_values(array_filter(self::active(), fn($n) => in_array($hook, $installed[$n]['manifest']['hooks'], true)));
    }

    // --- esecuzione -------------------------------------------------------

    /** Il plugin avviato (plugin.php incluso e agganci registrati), o null se è spento o rotto. */
    public static function context(string $name): ?Context
    {
        if (isset(self::$contexts[$name])) return self::$contexts[$name] ?: null;
        if (!in_array($name, self::active(), true)) return null;
        $manifest = self::installed()[$name]['manifest'];
        $context = new Context($manifest);
        $ok = self::run($name, function () use ($name, $context): bool {
            $entry = (static fn(string $file) => require $file)(self::root() . '/plugins/' . $name . '/plugin.php');
            if (!is_callable($entry)) throw new PluginException('plugin.php non restituisce una funzione.');
            $entry($context);
            return true;
        });
        self::$contexts[$name] = $ok === true ? $context : false;
        return $ok === true ? $context : null;
    }

    /** Chiama l'aggancio di un plugin; null se non c'è o se è andato in errore. */
    public static function call(string $name, string $hook, array $args = []): mixed
    {
        $context = self::context($name);
        $fn = $context?->hook($hook);
        if ($fn !== null && is_string($args[0]["lang"] ?? null)) $context->setLanguage($args[0]["lang"]);
        return $fn === null ? null : self::run($name, fn() => $fn(...$args));
    }

    /** Esegue codice del plugin: le eccezioni vanno nel registro, un errore fatale lo spegne. */
    private static function run(string $name, callable $work): mixed
    {
        self::guard();
        $previous = self::$running;
        self::$running = $name;
        try {
            return $work();
        } catch (Throwable $e) {
            self::recordError($name, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            return null;
        } finally {
            self::$running = $previous;
        }
    }

    private static function guard(): void
    {
        if (self::$guarded) return;
        self::$guarded = true;
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            $name = self::$running;
            if ($name === null || $error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
            $reason = $error['message'] . ' (' . basename((string) $error['file']) . ':' . $error['line'] . ')';
            try {
                self::updateState($name, fn(?array $e) => $e === null ? null : ['active' => false, 'disabled_reason' => $reason, 'last_error' => ['at' => date('c'), 'message' => $reason]] + $e);
                self::log($name, 'Spento per un errore fatale: ' . $reason);
            } catch (Throwable) {
                // Niente da fare: la prossima richiesta riprova
            }
        });
    }

    // --- sito -------------------------------------------------------------

    /**
     * Gli agganci della pagina pubblica: blocchi k-plugin, head, body_end.
     *
     * @param array{page?: string, type?: string, id?: int, lang?: string, status?: int} $page
     */
    public static function decorate(string $html, array $page): string
    {
        if (!str_contains($html, 'k-plugin') && self::active() === []) return $html;
        // Blocchi: <div k-plugin="plugin:nome" data-…></div>, sostituito come k-component; senza plugin sparisce
        $html = (string) preg_replace_callback(
            '#<([a-z][a-z0-9]*)\b([^>]*?)\sk-plugin=(["\'])([a-z0-9_]+):([a-z0-9_-]+)\3([^>]*)>\s*</\1>#i',
            function (array $m) use ($page): string {
                $attrs = [];
                if (preg_match_all('#\sdata-([a-z0-9-]+)=(["\'])(.*?)\2#i', $m[2] . ' ' . $m[6], $found, PREG_SET_ORDER)) {
                    foreach ($found as $a) $attrs[strtolower($a[1])] = html_entity_decode($a[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $out = self::call(strtolower($m[4]), 'block:' . strtolower($m[5]), [$page, $attrs]);
                return is_string($out) ? $out : '';
            },
            $html
        );
        foreach (['head' => '</head>', 'body_end' => '</body>'] as $hook => $tag) {
            $parts = [];
            foreach (self::withHook($hook) as $name) {
                $out = self::call($name, $hook, [$page]);
                if (is_string($out) && $out !== '') $parts[] = $out;
            }
            if (!$parts) continue;
            $pos = strripos($html, $tag);
            if ($pos !== false) $html = substr($html, 0, $pos) . implode("\n", $parts) . "\n" . substr($html, $pos);
        }
        return $html;
    }

    /**
     * index.php?kris_plugin=<plugin>&endpoint=<nome>: controlli del core, poi l'aggancio.
     *
     * @param array{method: string, query: array, body: array, lang: string, ip: string, files?: array, size?: int} $request
     * @return array{status: int, json?: mixed, text?: string, location?: string}
     */
    public static function endpoint(string $plugin, string $endpoint, array $request): array
    {
        $installed = self::installed()[$plugin] ?? null;
        $def = null;
        foreach ($installed['manifest']['endpoints'] ?? [] as $e) {
            if ($e['name'] === $endpoint) $def = $e;
        }
        if ($def === null || !in_array($plugin, self::active(), true)) return ['status' => 404, 'json' => ['ok' => false, 'error' => 'Non trovato.']];
        if (!in_array($request['method'], $def['methods'], true)) return ['status' => 405, 'json' => ['ok' => false, 'error' => 'Metodo non ammesso.']];
        if (!empty($request['files'])) return ['status' => 400, 'json' => ['ok' => false, 'error' => 'I caricamenti di file non sono ammessi.']];
        if (($request['size'] ?? 0) > self::MAX_BODY) return ['status' => 413, 'json' => ['ok' => false, 'error' => 'Richiesta troppo grande.']];
        if (!self::allowRate($plugin, $endpoint, $def['rate'], $request['ip'])) return ['status' => 429, 'json' => ['ok' => false, 'error' => 'Troppe richieste: riprova fra poco.']];
        if ($def['form_token'] && !self::validFormToken($plugin, $endpoint, (string) ($request['body']['kris_form_token'] ?? $request['query']['kris_form_token'] ?? ''))) {
            return ['status' => 403, 'json' => ['ok' => false, 'error' => 'Il modulo è scaduto: ricarica la pagina e riprova.']];
        }
        unset($request['files'], $request['ip'], $request['size']);
        $out = self::call($plugin, 'endpoint:' . $endpoint, [$request]);
        if (!is_array($out) || !is_int($out['status'] ?? null)) return ['status' => 500, 'json' => ['ok' => false, 'error' => 'Il plugin non ha risposto.']];
        if (isset($out['location'])) {
            $loc = (string) $out['location'];
            // Solo indirizzi del sito: nessun redirect aperto verso altri domini
            if (!str_starts_with($loc, '/') || str_starts_with($loc, '//') || str_contains($loc, '\\')) return ['status' => 500, 'json' => ['ok' => false, 'error' => 'Indirizzo non ammesso.']];
            return ['status' => in_array($out['status'], [301, 302, 303, 307], true) ? $out['status'] : 303, 'location' => $loc];
        }
        if (array_key_exists('text', $out)) return ['status' => $out['status'], 'text' => (string) $out['text']];
        return ['status' => $out['status'], 'json' => $out['json'] ?? null];
    }

    /** Un'azione di una schermata del plugin nell'editor (sessione e CSRF già controllati). */
    public static function editorAction(string $plugin, string $action, array $request): mixed
    {
        $installed = self::installed()[$plugin] ?? null;
        if ($installed === null || !in_array($action, $installed['manifest']['editor']['actions'], true)) {
            throw new PluginException('Questa azione del plugin non esiste.');
        }
        if (!in_array($plugin, self::active(), true)) throw new PluginException('Il plugin è spento: accendilo da Impostazioni › Plugin.');
        $context = self::context($plugin);
        $fn = $context?->hook('editor:' . $action);
        if ($fn === null) throw new PluginException('Il plugin non risponde a questa azione: guarda il suo registro.');
        $error = null;
        $out = self::run($plugin, function () use ($fn, $request, &$error) {
            try {
                return $fn($request);
            } catch (PluginException $e) {
                $error = $e; // un messaggio per chi usa l'editor, non un guasto
                return null;
            }
        });
        if ($error !== null) throw $error;
        return $out;
    }

    /**
     * published e content_saved: dopo la risposta all'admin quando l'hosting lo
     * consente (fastcgi_finish_request), al massimo 5 secondi per plugin.
     */
    public static function event(string $hook, array $payload): void
    {
        $names = self::withHook($hook);
        if (!$names) return;
        $work = static function () use ($names, $hook, $payload): void {
            foreach ($names as $name) {
                if (PHP_SAPI !== 'cli') @set_time_limit(5);
                self::call($name, $hook, [$payload]);
            }
        };
        if (PHP_SAPI === 'cli') { $work(); return; }
        register_shutdown_function(static function () use ($work): void {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            $work();
        });
    }

    /**
     * Cosa riceve published: file e operazioni pubblicate, pagine toccate con
     * i loro indirizzi pubblici assoluti, in ogni lingua.
     */
    public static function publishedPayload(array $files, array $ops, array $pages): array
    {
        $out = [];
        foreach ($pages as $p) {
            parse_str((string) parse_url((string) $p['url'], PHP_URL_QUERY), $q);
            unset($q['kris_preview']);
            $out[] = ['label' => (string) $p['label'], 'query' => $q, 'urls' => self::publicUrls($q)];
        }
        return ['files' => $files, 'operations' => array_map(fn($o) => array_intersect_key($o, array_flip(['op', 'key', 'id', 'path', 'name'])), $ops), 'pages' => $out];
    }

    /** Gli indirizzi canonici assoluti di una pagina (page, key, id, path), uno per lingua. */
    public static function publicUrls(array $q): array
    {
        $langs = Site::languages(self::root());
        $base = \Kris\Template\Seo::baseUrl(\Kris\Template\Seo::config(self::root()), $_SERVER);
        // Le rotte leggibili si calcolano sul sito della richiesta (negli strumenti può essere un altro)
        $routes = self::root() === rtrim(str_replace('\\', '/', KRIS_ROOT), '/') ? \Kris\Site\PageRenderer::routes() : null;
        $path = isset($q['path']) && $q['path'] !== '' ? explode('/', (string) $q['path']) : [];
        $urls = [];
        foreach ($langs as $lang) {
            $urls[] = \Kris\Template\Seo::url($base, (string) ($q['page'] ?? 'homepage'), (string) ($q['key'] ?? 'homepage'), (int) ($q['id'] ?? 0), $path, $lang, $langs[0], $routes);
        }
        return array_values(array_unique($urls));
    }

    /** Gli indirizzi pubblici di un contenuto: le pagine della sitemap che lo rendono, in ogni lingua. */
    public static function contentUrls(string $type, int $id): array
    {
        $urls = [];
        foreach (Site::load(self::root())['schema']['pages'] ?? [] as $page => $p) {
            if (!is_array($p) || ($p['type'] ?? null) !== $type || isset($p['list']) || ($p['sitemap'] ?? false) !== true) continue;
            if (isset($p['item']) && (int) $p['item'] !== $id) continue;
            array_push($urls, ...self::publicUrls(['page' => (string) $page, 'key' => $type, 'id' => $id]));
        }
        return array_values(array_unique($urls));
    }

    // --- privacy e avvisi -------------------------------------------------

    /** Il registro dei cookie: quelli dichiarati dai plugin accesi, con il plugin. */
    public static function cookies(): array
    {
        $out = [];
        $installed = self::installed();
        foreach (self::active() as $name) {
            foreach ($installed[$name]['manifest']['cookies'] as $c) $out[] = $c + ['plugin' => $installed[$name]['manifest']['title']];
        }
        return $out;
    }

    /** I domini esterni dei plugin accesi, per il riepilogo della privacy. */
    public static function domains(): array
    {
        $out = [];
        foreach (self::active() as $name) array_push($out, ...self::installed()[$name]['manifest']['domains']);
        return array_values(array_unique($out));
    }

    /** Gli avvisi per ogni schermata dell'editor. */
    public static function alerts(): array
    {
        $alerts = [];
        if (self::safeMode()) $alerts[] = ['kind' => 'warning', 'text' => 'Modalità sicura: config/plugins.php spegne tutti i plugin. Toglila quando il sito risponde di nuovo.'];
        foreach (self::installed() as $p) {
            if (($p['state']['active'] ?? false) !== true && is_string($p['state']['disabled_reason'] ?? null)) {
                $alerts[] = ['kind' => 'danger', 'text' => "Plugin disattivato: {$p['manifest']['title']}. Motivo: {$p['state']['disabled_reason']}", 'plugin' => $p['manifest']['name']];
            }
        }
        $banner = null;
        foreach (self::cookies() as $c) {
            if ($c['category'] === 'technical') continue;
            $banner ??= self::hasConsentBanner();
            if (!$banner) {
                $alerts[] = ['kind' => 'warning', 'text' => "Il plugin {$c['plugin']} usa cookie che richiedono il consenso: serve il banner dei cookie nel tema."];
                break;
            }
        }
        return $alerts;
    }

    private static function hasConsentBanner(): bool
    {
        foreach (glob(self::root() . '/template/*.html') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'data-kris-consent-banner')) return true;
        }
        return false;
    }

    // --- registro ---------------------------------------------------------

    public static function log(string $name, string $message): void
    {
        $dir = self::root() . '/' . self::LOGS;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
        $file = "{$dir}/{$name}.log";
        $lines = self::logLines($name);
        $lines[] = date('c') . ' ' . str_replace(["\r", "\n"], ' ', mb_substr($message, 0, 500));
        @file_put_contents($file, implode("\n", array_slice($lines, -self::LOG_LINES)) . "\n", LOCK_EX);
    }

    /** @return list<string> le ultime righe del registro, dalla più vecchia */
    public static function logLines(string $name): array
    {
        $raw = @file_get_contents(self::root() . '/' . self::LOGS . "/{$name}.log");
        return $raw === false ? [] : array_values(array_filter(explode("\n", $raw), fn($l) => $l !== ''));
    }

    public static function recordError(string $name, string $message): void
    {
        self::log($name, 'Errore: ' . $message);
        try {
            self::updateState($name, fn(?array $e) => $e === null ? null : ['last_error' => ['at' => date('c'), 'message' => mb_substr($message, 0, 500)]] + $e);
        } catch (Throwable) {
        }
    }

    // --- endpoint: frequenza e gettone dei moduli --------------------------

    private static function allowRate(string $plugin, string $endpoint, string $rate, string $ip): bool
    {
        preg_match('/^(\d+)\/(\d+)([smh])$/', $rate, $m);
        $max = (int) $m[1];
        $window = (int) $m[2] * ['s' => 1, 'm' => 60, 'h' => 3600][$m[3]];
        $file = self::root() . '/' . self::RATE;
        $handle = @fopen($file, 'c+');
        if ($handle === false) return true; // senza file il limite non si applica: meglio che rifiutare tutti
        flock($handle, LOCK_EX);
        try {
            $data = json_decode((string) stream_get_contents($handle), true);
            $data = is_array($data) ? $data : [];
            $now = time();
            // L'IP non si salva in chiaro, come per gli accessi all'editor
            $key = substr(hash('sha256', "kris-plugin|{$plugin}|{$endpoint}|{$ip}"), 0, 20);
            foreach ($data as $k => $hits) {
                $data[$k] = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - 86400));
                if (!$data[$k]) unset($data[$k]);
            }
            $mine = array_values(array_filter($data[$key] ?? [], fn($t) => $t > $now - $window));
            if (count($mine) >= $max) return false;
            $data[$key] = [...($data[$key] ?? []), $now];
            // Al massimo 5000 indirizzi ricordati: i più vecchi escono per primi
            if (count($data) > 5000) $data = array_slice($data, -5000, null, true);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Gettone di un modulo pubblico: HMAC con la chiave del sito, valido 2 ore, legato a plugin ed endpoint. */
    public static function formToken(string $plugin, string $endpoint, ?int $now = null): string
    {
        $now ??= time();
        return $now . '.' . hash_hmac('sha256', "{$plugin}|{$endpoint}|{$now}", self::key());
    }

    public static function validFormToken(string $plugin, string $endpoint, string $token, ?int $now = null): bool
    {
        $now ??= time();
        if (preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m) !== 1) return false;
        $at = (int) $m[1];
        if ($at > $now + 60 || $at < $now - self::FORM_TOKEN_TTL) return false;
        return hash_equals(hash_hmac('sha256', "{$plugin}|{$endpoint}|{$at}", self::key()), $m[2]);
    }

    private static function key(): string
    {
        $file = self::root() . '/' . self::KEY;
        $key = is_file($file) ? require $file : null;
        if (is_string($key) && strlen($key) >= 32) return $key;
        $key = bin2hex(random_bytes(32));
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
        @file_put_contents($file, "<?php\n// Chiave dei gettoni dei moduli dei plugin: privata, esclusa da Git.\nreturn '{$key}';\n", LOCK_EX);
        return $key;
    }

    /** I valori delle impostazioni del plugin (tipo plg_<nome>_settings), nella lingua chiesta. */
    public static function settings(string $name, string $lang): array
    {
        $site = Site::load(self::root());
        $fields = Site::fields($site['schema'], "plg_{$name}_settings");
        foreach ($site['content'] as $item) {
            if (($item['type'] ?? null) !== "plg_{$name}_settings") continue;
            $entity = new \Kris\Entity\Entity("plg_{$name}_settings", 0, is_array($item['values'] ?? null) ? $item['values'] : [], $fields);
            $out = [];
            foreach (array_keys($fields) as $field) {
                $v = $entity->getData($field, $lang);
                $out[$field] = $fields[$field]['type'] === 'boolean' ? $v === '1' : $v;
            }
            return $out;
        }
        return [];
    }
}
