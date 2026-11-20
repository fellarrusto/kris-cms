<?php
declare(strict_types=1);

namespace Kris\Plugin;

use Kris\Store\Site;
use Kris\Store\Stores;
use Kris\Update\Http;
use Kris\Update\Package;
use Kris\Update\Signature;
use Kris\Update\UpdateException;
use Kris\Update\Updater;
use Throwable;

/**
 * Catalogo, installazione, aggiornamento e rimozione dei plugin
 * (docs/2.0/plugin.md, 8). Solo le repository fellarrusto/kris-plugin-<nome>,
 * solo pacchetti firmati con le chiavi del core.
 *
 * Installare: scaricamento, verifica di firma e hash, controllo del manifest
 * e della sintassi PHP; copia dei documenti, scambio di plugins/<nome>/ e
 * assets/plugins/<nome>/, migrazioni, tipo delle impostazioni, stato. Un
 * errore in uno di questi passi rimette tutto com'era.
 */
final class Installer
{
    public const CATALOG = 'https://raw.githubusercontent.com/fellarrusto/kris-cms/main/plugins.json';
    public const OWNER = 'fellarrusto';
    public const PUBLIC_EXTENSIONS = ['css', 'js', 'svg', 'png', 'jpg', 'webp', 'avif', 'gif', 'woff2'];
    private const JSON_MAX = 512 * 1024;

    private string $root;
    private array $keys;
    private Http $http;
    private string $catalogUrl;
    private string $krisVersion;

    public function __construct(?array $keys = null, ?Http $http = null, ?string $catalogUrl = null, ?string $krisVersion = null)
    {
        $this->root = Plugins::root();
        $this->keys = $keys ?? Signature::trustedKeys();
        $this->http = $http ?? new Http();
        $this->catalogUrl = $catalogUrl ?? self::CATALOG;
        $this->krisVersion = $krisVersion ?? trim((string) @file_get_contents(KRIS_DIR . '/VERSION'));
    }

    /** kris-plugin-<nome con - al posto di _> */
    public static function repository(string $name): string
    {
        return 'kris-plugin-' . str_replace('_', '-', $name);
    }

    // --- catalogo ---------------------------------------------------------

    /** I plugin ufficiali: nome, titolo, descrizione. */
    public function catalog(): array
    {
        $data = $this->signedJson($this->catalogUrl);
        $out = [];
        foreach (is_array($data['plugins'] ?? null) ? $data['plugins'] : [] as $p) {
            if (!is_array($p) || !is_string($p['name'] ?? null) || preg_match(Manifest::NAME, $p['name']) !== 1) continue;
            $out[] = ['name' => $p['name'], 'title' => (string) ($p['title'] ?? $p['name']), 'description' => (string) ($p['description'] ?? '')];
        }
        return $out;
    }

    /** Le versioni di un plugin, dalla più recente, con requisiti e changelog. */
    public function releases(string $name): array
    {
        if (preg_match(Manifest::NAME, $name) !== 1) throw new PluginException('Nome del plugin non valido.');
        $repo = self::repository($name);
        $data = $this->signedJson('https://raw.githubusercontent.com/' . self::OWNER . "/{$repo}/main/releases.json");
        $out = [];
        foreach (is_array($data['releases'] ?? null) ? $data['releases'] : [] as $r) {
            if (!is_array($r) || !is_string($r['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/', $r['version']) !== 1) continue;
            if (!is_string($r['zip'] ?? null) || !str_starts_with($r['zip'], 'https://github.com/' . self::OWNER . "/{$repo}/releases/download/")) continue;
            if (!is_string($r['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $r['sha256']) !== 1) continue;
            $requires = is_array($r['requires'] ?? null) ? $r['requires'] : [];
            $out[] = ['version' => $r['version'], 'zip' => $r['zip'], 'sha256' => $r['sha256'],
                'requires' => ['kris' => (string) ($requires['kris'] ?? '2.0.0'), 'php' => (string) ($requires['php'] ?? '8.3')],
                'changelog' => (string) ($r['changelog_it'] ?? ''), 'date' => (string) ($r['date'] ?? '')];
        }
        usort($out, fn($a, $b) => version_compare($b['version'], $a['version']));
        return $out;
    }

    /** La versione più recente installabile qui, o null. */
    public function latestCompatible(array $releases): ?array
    {
        foreach ($releases as $r) {
            if (version_compare($this->krisVersion, $r['requires']['kris'], '>=') && version_compare(PHP_VERSION, $r['requires']['php'], '>=')) return $r;
        }
        return null;
    }

    // --- installazione ----------------------------------------------------

    /** Scarica e installa (o aggiorna) un plugin dal catalogo; $version null: l'ultima compatibile. */
    public function install(string $name, ?string $version, string $user): array
    {
        $this->assertNoStaging();
        $releases = $this->releases($name);
        $release = $version === null ? $this->latestCompatible($releases) : (array_values(array_filter($releases, fn($r) => $r['version'] === $version))[0] ?? null);
        if ($release === null) throw new PluginException($version === null ? 'Nessuna versione del plugin va su questo sito.' : "La versione {$version} del plugin non esiste.");
        $dir = $this->workDir();
        try {
            $zip = $dir . '.zip';
            try {
                $bytes = $this->http->get($release['zip'], Package::MAX_ZIP_BYTES);
            } catch (UpdateException $e) {
                throw new PluginException('Scaricamento non riuscito: ' . $e->getMessage());
            }
            if (!hash_equals($release['sha256'], hash('sha256', $bytes))) throw new PluginException('Il pacchetto scaricato non corrisponde a quello pubblicato: nulla è stato installato.');
            if (@file_put_contents($zip, $bytes) === false) throw new PluginException('Non riesco a salvare il pacchetto in data/updates/.');
            return $this->installZip($zip, $name, $user);
        } finally {
            @unlink($dir . '.zip');
        }
    }

    /** Installa da uno zip già sul server (anche per i test): verifica tutto prima di toccare il sito. */
    public function installZip(string $zipFile, string $expectedName, string $user): array
    {
        $this->assertNoStaging();
        $work = $this->workDir();
        try {
            try {
                Package::extract($zipFile, $work, 'plugin');
                $signed = Package::verifyFiles($work . '/plugin', $this->keys);
            } catch (UpdateException $e) {
                throw new PluginException($e->getMessage());
            }
            $manifest = Manifest::parse((string) @file_get_contents($work . '/plugin/plugin.json'));
            if ($manifest['name'] !== $expectedName) throw new PluginException('Il pacchetto contiene un altro plugin: rifiutato.');
            if ($signed['version'] !== $manifest['version']) throw new PluginException('Il pacchetto dichiara versioni diverse in punti diversi: rifiutato.');
            if (($unmet = Manifest::unmet($manifest, $this->krisVersion)) !== null) throw new PluginException($unmet);
            $this->checkFiles($work . '/plugin', $manifest);
            $this->swap($work, $manifest, $user);
            Plugins::log($manifest['name'], "Installata la versione {$manifest['version']} da {$user}.");
            return $manifest;
        } finally {
            Updater::removeTree($work);
            Plugins::reset();
        }
    }

    public function setActive(string $name, bool $active): void
    {
        if (!isset(Plugins::installed()[$name])) throw new PluginException('Il plugin non è installato.');
        Plugins::updateState($name, function (?array $e) use ($active): ?array {
            if ($e === null) return null;
            $e['active'] = $active;
            unset($e['disabled_reason']);
            return $e;
        });
        Plugins::log($name, $active ? 'Acceso.' : 'Spento.');
        Plugins::reset();
    }

    /** Toglie il plugin; con $deleteData anche i suoi tipi plg_<nome>_* e i loro contenuti. */
    public function remove(string $name, bool $deleteData): void
    {
        if (preg_match(Manifest::NAME, $name) !== 1 || !isset(Plugins::state()['plugins'][$name])) throw new PluginException('Il plugin non è installato.');
        if ($deleteData) {
            $this->assertNoStaging();
            $prefix = "plg_{$name}_";
            Stores::site($this->root)->transaction(['schema', 'content'], function (array $docs) use ($prefix): array {
                $docs['schema']['types'] = array_filter($docs['schema']['types'] ?? [], fn($t) => !str_starts_with((string) $t, $prefix), ARRAY_FILTER_USE_KEY);
                $docs['content'] = array_values(array_filter($docs['content'], fn($c) => !str_starts_with((string) ($c['type'] ?? ''), $prefix)));
                return $docs;
            });
            Site::forget();
        }
        Updater::removeTree($this->root . '/plugins/' . $name);
        Updater::removeTree($this->root . '/assets/plugins/' . $name);
        @unlink($this->root . '/' . Plugins::LOGS . "/{$name}.log");
        Plugins::updateState($name, fn() => null);
        Plugins::reset();
    }

    // --- interni ----------------------------------------------------------

    private function assertNoStaging(): void
    {
        if ((new \Kris\Staging\Staging($this->root))->hasChanges()) {
            throw new PluginException('Ci sono modifiche in attesa: pubblicale o scartale prima di installare o togliere un plugin.');
        }
    }

    private function workDir(): string
    {
        $dir = $this->root . '/data/updates';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new PluginException('Non riesco a creare data/updates/.');
        return $dir . '/plugin-' . bin2hex(random_bytes(6));
    }

    /** File pubblici ammessi, PHP solo dove previsto, con la guardia e senza errori di sintassi. */
    private function checkFiles(string $dir, array $manifest): void
    {
        if (!is_file($dir . '/plugin.php')) throw new PluginException('Il pacchetto non ha plugin.php.');
        foreach (Package::listFiles($dir) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (str_starts_with($file, 'public/')) {
                if (!in_array($ext, self::PUBLIC_EXTENSIONS, true)) throw new PluginException("File pubblico non ammesso: {$file}.");
                continue;
            }
            if (basename($file) === '.htaccess' || in_array($ext, ['phtml', 'phar', 'php5', 'php7', 'phps'], true)) throw new PluginException("File non ammesso: {$file}.");
            if ($ext !== 'php') continue;
            if (!($file === 'plugin.php' || str_starts_with($file, 'src/') || preg_match('#^migrations/\d{4}_[a-z0-9_]+\.php$#', $file) === 1)) {
                throw new PluginException("File PHP fuori posto: {$file} (solo plugin.php, src/ e migrations/).");
            }
            $code = (string) file_get_contents($dir . '/' . $file);
            if (!str_starts_with($code, '<?php') || !preg_match("#defined\\(\\s*'KRIS_ROOT'\\s*\\)\\s*\\|\\|\\s*exit#", substr($code, 0, 400))) {
                throw new PluginException("{$file} non si apre con defined('KRIS_ROOT') || exit;");
            }
            try {
                token_get_all($code, TOKEN_PARSE);
            } catch (\ParseError $e) {
                throw new PluginException("Errore di sintassi in {$file}, riga {$e->getLine()}: nulla è stato installato.");
            }
        }
        $module = $manifest['editor']['module'];
        if ($module !== null && !is_file($dir . '/public/' . $module)) throw new PluginException("Manca il modulo dell'editor public/{$module}.");
    }

    private function swap(string $work, array $manifest, string $user): void
    {
        $name = $manifest['name'];
        $store = Stores::site($this->root);
        $documents = $store->export();
        $target = $this->root . '/plugins/' . $name;
        $assets = $this->root . '/assets/plugins/' . $name;
        $moved = [];
        try {
            // I file pubblici, già nella forma finale, accanto ad assets/plugins/
            $newAssets = $work . '/assets';
            if (!@mkdir($newAssets, 0775, true)) throw new PluginException('Non riesco a preparare i file pubblici.');
            if (is_dir($work . '/plugin/public')) {
                foreach (Package::listFiles($work . '/plugin/public') as $file) {
                    if (!is_dir(dirname("{$newAssets}/{$file}"))) @mkdir(dirname("{$newAssets}/{$file}"), 0775, true);
                    if (!@copy("{$work}/plugin/public/{$file}", "{$newAssets}/{$file}")) throw new PluginException('Non riesco a preparare i file pubblici.');
                }
            }
            foreach ([[$target, $work . '/plugin', $work . '/old-plugin'], [$assets, $newAssets, $work . '/old-assets']] as [$live, $new, $old]) {
                if (!is_dir(dirname($live)) && !@mkdir(dirname($live), 0775, true)) throw new PluginException('Non riesco a creare ' . dirname($live) . '.');
                // plugins/ non si serve: Apache la nega, e ogni file PHP si apre con la guardia KRIS_ROOT
                if ($live === $target && !is_file(dirname($live) . '/.htaccess')) @file_put_contents(dirname($live) . '/.htaccess', "Require all denied
");
                if (is_dir($live)) {
                    if (!@rename($live, $old)) throw new PluginException("Non riesco a spostare {$live}: controlla i permessi.");
                    $moved[] = [$old, $live];
                }
                if (!@rename($new, $live)) throw new PluginException("Non riesco a scrivere {$live}: controlla i permessi.");
                $moved[] = [null, $live];
            }

            $applied = Plugins::state()['plugins'][$name]['migrations'] ?? [];
            $applied = $this->migrate($target, $manifest, is_array($applied) ? $applied : []);

            Plugins::updateState($name, fn(?array $e) => [
                'version' => $manifest['version'],
                'active' => ($e['active'] ?? false) === true,
                'installed_at' => (string) ($e['installed_at'] ?? date('c')),
                'updated_at' => date('c'),
                'installed_by' => $user,
                'migrations' => $applied,
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            // Tutto com'era: cartelle e documenti
            foreach (array_reverse($moved) as [$old, $live]) {
                if ($old === null) Updater::removeTree($live);
                else @rename($old, $live);
            }
            try {
                $store->import($documents);
            } catch (Throwable) {
            }
            Site::forget();
            throw $e instanceof PluginException ? $e : new PluginException('Installazione non riuscita, nulla è cambiato: ' . $e->getMessage());
        }
    }

    /**
     * Migrazioni del plugin non ancora applicate e tipo delle impostazioni, in
     * una transazione; una migrazione che tocca altro che i tipi del plugin è rifiutata.
     *
     * @return list<string> le migrazioni applicate
     */
    private function migrate(string $dir, array $manifest, array $applied): array
    {
        $own = $manifest['types'];
        $files = glob($dir . '/migrations/*.php') ?: [];
        sort($files);
        Stores::site($this->root)->transaction(['schema', 'content', 'settings'], function (array $docs) use ($files, $own, $manifest, &$applied): array {
            foreach ($files as $file) {
                $id = basename($file, '.php');
                if (in_array($id, $applied, true)) continue;
                $migration = (static fn(string $f) => require $f)($file);
                if (!is_array($migration) || !is_callable($migration['up'] ?? null)) throw new PluginException("La migrazione {$id} del plugin non è valida.");
                $next = ($migration['up'])(['schema' => $docs['schema'], 'content' => $docs['content'], 'settings' => $docs['settings']]);
                if (!is_array($next) || !is_array($next['schema'] ?? null) || !is_array($next['content'] ?? null)) throw new PluginException("La migrazione {$id} del plugin non restituisce i documenti.");
                $next['settings'] ??= $docs['settings'];
                if (self::others($docs, $own) !== self::others($next, $own)) throw new PluginException("La migrazione {$id} tocca dati che non sono del plugin: rifiutata.");
                $docs = ['schema' => $next['schema'], 'content' => array_values($next['content']), 'settings' => $next['settings']];
                $applied[] = $id;
            }
            // Il tipo delle impostazioni viene dal manifest; i tipi del plugin sono riservati
            $settingsType = "plg_{$manifest['name']}_settings";
            if ($manifest['settings']) {
                $docs['schema']['types'][$settingsType] = ['label' => $manifest['title'], 'single' => true, 'reserved' => true, 'fields' => $manifest['settings']];
                $exists = false;
                foreach ($docs['content'] as $c) $exists = $exists || ($c['type'] ?? null) === $settingsType;
                if (!$exists) $docs['content'][] = ['type' => $settingsType, 'id' => 0, 'values' => []];
            }
            foreach ($own as $type) {
                if (isset($docs['schema']['types'][$type])) $docs['schema']['types'][$type]['reserved'] = true;
            }
            return $docs;
        });
        Site::forget();
        return $applied;
    }

    /** I dati che non sono del plugin: devono restare identici. */
    private static function others(array $docs, array $own): array
    {
        $schema = $docs['schema'];
        $schema['types'] = array_diff_key(is_array($schema['types'] ?? null) ? $schema['types'] : [], array_flip($own));
        return [$schema, array_values(array_filter($docs['content'], fn($c) => !in_array($c['type'] ?? null, $own, true))), $docs['settings']];
    }

    private function signedJson(string $url): array
    {
        try {
            $body = $this->http->get($url, self::JSON_MAX);
            $sig = $this->http->get($url . '.sig', 4096);
        } catch (UpdateException $e) {
            throw new PluginException('Il catalogo dei plugin non risponde: ' . $e->getMessage());
        }
        if (!Signature::verify($body, $sig, $this->keys)) throw new PluginException('La firma del catalogo dei plugin non è valida: nulla verrà installato.');
        $data = json_decode($body, true);
        if (!is_array($data)) throw new PluginException('Il catalogo dei plugin è illeggibile.');
        return $data;
    }
}
